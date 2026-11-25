<?php

namespace App\Services\Advertising\Adapters;

use App\Exceptions\OAuthTokenExpiredException;
use App\Exceptions\ProviderRateLimitException;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MetaAdsAdapter implements AdvertisingProviderInterface
{
    protected ?ProviderDeveloperConfig $config;
    protected string $apiVersion = 'v21.0';

    public function __construct(?ProviderDeveloperConfig $config = null)
    {
        $this->config = $config ?? ProviderDeveloperConfig::where('provider', 'meta')->first();
        if ($this->config && !empty($this->config->api_version)) {
            $this->apiVersion = $this->config->api_version;
        }
    }

    public function getProviderName(): string
    {
        return 'meta';
    }

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        $this->ensureConfigured();

        $params = http_build_query([
            'client_id' => $this->config->app_id,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => 'ads_read,ads_management,read_insights',
        ]);

        return "https://www.facebook.com/{$this->apiVersion}/dialog/oauth?{$params}";
    }

    public function handleOAuthCallback(string $code, string $redirectUri): array
    {
        $this->ensureConfigured();

        $response = $this->executeHttp(function () use ($code, $redirectUri) {
            return Http::timeout(30)->connectTimeout(10)
                ->get("https://graph.facebook.com/{$this->apiVersion}/oauth/access_token", [
                    'client_id' => $this->config->app_id,
                    'client_secret' => $this->config->app_secret,
                    'redirect_uri' => $redirectUri,
                    'code' => $code,
                ]);
        }, 'meta:oauth/access_token');

        if ($response->failed()) {
            throw new RuntimeException("Meta OAuth exchange failed: " . $response->body());
        }

        $data = $response->json();
        $shortLivedToken = $data['access_token'];

        // Exchange for long-lived user access token (valid ~60 days)
        $longLivedResponse = $this->executeHttp(function () use ($shortLivedToken) {
            return Http::timeout(30)->connectTimeout(10)
                ->get("https://graph.facebook.com/{$this->apiVersion}/oauth/access_token", [
                    'grant_type' => 'fb_exchange_token',
                    'client_id' => $this->config->app_id,
                    'client_secret' => $this->config->app_secret,
                    'fb_exchange_token' => $shortLivedToken,
                ]);
        }, 'meta:oauth/fb_exchange_token');

        if ($longLivedResponse->successful()) {
            $longLivedData = $longLivedResponse->json();
            return [
                'access_token' => $longLivedData['access_token'],
                'refresh_token' => null,
                'expires_at' => now()->addSeconds($longLivedData['expires_in'] ?? 5184000),
            ];
        }

        return [
            'access_token' => $shortLivedToken,
            'refresh_token' => null,
            'expires_at' => now()->addSeconds($data['expires_in'] ?? 7200),
        ];
    }

    public function discoverAccounts(string $accessToken): array
    {
        $accounts = [];
        $nextUrl = "https://graph.facebook.com/{$this->apiVersion}/me/adaccounts";
        $params = [
            'fields' => 'id,account_id,name,currency,timezone_name,account_status',
            'limit' => 100,
        ];

        $seenPages=[];$ids=[];$pages=0;
        do {
            $this->validateHierarchyPageUrl($nextUrl,"/{$this->apiVersion}/me/adaccounts");
            if(++$pages>100||isset($seenPages[$nextUrl]))throw new RuntimeException('Meta account discovery page cycle or limit; partial inventory rejected.');
            $seenPages[$nextUrl]=true;
            $response = $this->executeHttp(function () use ($accessToken, $nextUrl, $params) {
                return Http::timeout(30)->connectTimeout(10)->withoutRedirecting()
                    ->withToken($accessToken)
                    ->get($nextUrl, $params);
            }, 'meta:me/adaccounts');

            if ($response->failed()) {
                throw new RuntimeException("Failed to discover Meta ad accounts: " . $response->body());
            }

            $json = $response->json();
            $items = $this->pageItems($json);

            foreach ($items as $item) {
                if(!is_array($item)||!isset($item['id'])||!is_string($item['id'])&&!is_int($item['id']))throw new RuntimeException('Meta account descriptor malformed.');
                $node=$this->objectId($item['id']);
                $id=array_key_exists('account_id',$item)?$this->objectId($item['account_id']):str_replace('act_','',$node);
                if($node!=='act_'.$id)throw new RuntimeException('Meta discovered account IDs conflict.');
                if($id===''||isset($ids[$id]))throw new RuntimeException('Meta account ID missing or repeated; discovery rejected.');
                $ids[$id]=true;
                $accounts[] = [
                    'provider' => 'meta',
                    'external_account_id' => $id,
                    'account_name' => $item['name'] ?? 'Meta Ad Account ' . ($item['account_id'] ?? $item['id']),
                    'currency' => is_string($item['currency']??null)&&preg_match('/^[A-Z]{3}$/',$item['currency'])?$item['currency']:null,
                    'timezone' => is_string($item['timezone_name']??null)&&in_array($item['timezone_name'],\DateTimeZone::listIdentifiers(),true)?$item['timezone_name']:null,
                    'raw_status' => $item['account_status'] ?? null,
                    'metadata_source'=>['version'=>'meta-discovery-explicit-1','currency'=>$item['currency']??null,'timezone'=>$item['timezone_name']??null],
                ];
            }

            $nextUrl = $this->nextPage($json);
            $params = []; // subsequent pages already include full query string in nextUrl
        } while ($nextUrl!==null);

        return $accounts;
    }

    public function refreshToken(string $refreshToken): array
    {
        throw new RuntimeException("Meta does not support refresh tokens. Re-authorization is required.");
    }

    public function fetchCampaigns(ConnectedAdAccount $account): array
    {
        $actId = str_starts_with($account->external_account_id, 'act_')
            ? $account->external_account_id
            : 'act_' . $account->external_account_id;

        $campaigns = [];
        $rawResponses = [];
        $nextUrl = "https://graph.facebook.com/{$this->apiVersion}/{$actId}/campaigns";
        $params = [
            'fields' => 'id,name,status,objective,daily_budget,lifetime_budget,start_time,stop_time,adsets{id,name,status,daily_budget,targeting,ads{id,name,status,creative{id,name,object_type}}}',
            'limit' => 100,
        ];
        $pageCount = 0;
        $maxPages = 100;
        $hierarchyRequests = 0;
        $visitedCampaignPages = [];
        $campaignIds = [];

        do {
            $pageCount++;
            if ($pageCount > $maxPages) {
                throw new RuntimeException("Meta hierarchy campaign page limit exceeded; partial inventory rejected.");
            }

            $this->validateHierarchyPageUrl($nextUrl, "/{$this->apiVersion}/{$actId}/campaigns");
            if(isset($visitedCampaignPages[$nextUrl]) || ++$hierarchyRequests>1000)throw new RuntimeException('Meta hierarchy page cycle or request limit; partial inventory rejected.');
            $visitedCampaignPages[$nextUrl]=true;
            $response = $this->executeWithTokenHandling($account, function () use ($account, $nextUrl, $params) {
                return Http::timeout(30)->connectTimeout(10)->withoutRedirecting()
                    ->withToken($account->access_token)
                    ->get($nextUrl, $params);
            }, "meta:{$actId}/campaigns");

            if ($response->failed()) {
                throw new RuntimeException("Failed to fetch Meta campaigns: " . $response->body());
            }

            $raw = $response->json();
            if(!is_array($raw)||!isset($raw['data'])||!is_array($raw['data'])||!array_is_list($raw['data']))throw new RuntimeException('Meta campaign hierarchy response is malformed.');
            $rawResponses[] = $raw;

            foreach ($raw['data'] ?? [] as $item) {
                if(!is_array($item)||!isset($item['id'])||!is_string($item['id'])&&!is_int($item['id']))throw new RuntimeException('Meta campaign ID missing.');
                $item['id']=$this->objectId($item['id']);
                if(isset($campaignIds[(string)$item['id']]))throw new RuntimeException('Meta duplicate campaign object; partial inventory rejected.');
                $campaignIds[(string)$item['id']]=true;
                if(array_key_exists('adsets',$item))$item['adsets']=$this->completeHierarchyEdge($account,$item['adsets'],(string)$item['id'],'adsets',$rawResponses,$hierarchyRequests);
                $adSets = [];
                if (!empty($item['adsets']['data'])) {
                    foreach ($item['adsets']['data'] as $as) {
                        if(array_key_exists('ads',$as))$as['ads']=$this->completeHierarchyEdge($account,$as['ads'],(string)$as['id'],'ads',$rawResponses,$hierarchyRequests);
                        $ads = [];
                        if (!empty($as['ads']['data'])) {
                            foreach ($as['ads']['data'] as $ad) {
                                $ads[] = [
                                    'external_ad_id' => (string) $ad['id'],
                                    'name' => $ad['name'] ?? 'Ad ' . $ad['id'],
                                    'status' => strtoupper($ad['status'] ?? 'UNKNOWN'),
                                    'canonical_creative' => isset($ad['creative']['id']) ? ['external_creative_id'=>$this->objectId($ad['creative']['id']),'name'=>$ad['creative']['name']??null,'object_type'=>$ad['creative']['object_type']??null] : null,
                                ];
                            }
                        }
                        $audienceReferences=[];
                        foreach(['custom_audiences'=>'included','excluded_custom_audiences'=>'excluded']as$field=>$role){
                            if(is_array($as['targeting']??null)&&array_key_exists($field,$as['targeting'])){
                                $source=$as['targeting'][$field];
                                if(!is_array($source)||!array_is_list($source))throw new RuntimeException('Meta audience reference collection is malformed.');
                                $audienceReferences[$role]=array_map(function($ref){
                                    if(!is_array($ref)||!isset($ref['id'])||!is_string($ref['id'])&&!is_int($ref['id']))throw new RuntimeException('Meta audience object ID missing.');
                                    return ['external_audience_id'=>$this->objectId($ref['id']),'name'=>$ref['name']??null];
                                },$source);
                            }
                        }
                        $adSets[] = [
                            'external_ad_set_id' => (string) $as['id'],
                            'name' => $as['name'] ?? 'Ad Set ' . $as['id'],
                            'status' => strtoupper($as['status'] ?? 'UNKNOWN'),
                            'daily_budget' => isset($as['daily_budget']) ? $this->metricOperand($as,'daily_budget') / 100 : null,
                            'targeting' => $as['targeting'] ?? null,
                            'canonical_audience_references'=>$audienceReferences?:null,
                            'ads' => $ads,
                        ];
                    }
                }

                $campaigns[] = [
                    'external_campaign_id' => (string) $item['id'],
                    'name' => $item['name'] ?? 'Unnamed Campaign',
                    'status' => strtoupper($item['status'] ?? 'UNKNOWN'),
                    'objective' => $item['objective'] ?? null,
                    'daily_budget' => isset($item['daily_budget']) ? $this->metricOperand($item,'daily_budget') / 100 : null,
                    'lifetime_budget' => isset($item['lifetime_budget']) ? $this->metricOperand($item,'lifetime_budget') / 100 : null,
                    'currency' => $account->currency,
                    'start_date' => $item['start_time'] ?? null,
                    'end_date' => $item['stop_time'] ?? null,
                    'metadata' => $item,
                    'ad_sets' => $adSets,
                ];
            }

            $nextUrl = $this->nextPage($raw);
            $params = [];
        } while ($nextUrl!==null);

        return [
            'endpoint' => "/{$actId}/campaigns",
            'raw' => $rawResponses,
            'campaigns' => $campaigns,
        ];
    }

    /** Bounded edge traversal. Returned pages are retained with the hierarchy evidence. */
    private function completeHierarchyEdge(ConnectedAdAccount $account, mixed $edge, string $parent, string $name, array &$rawResponses, int &$requests): array
    {
        if(!is_array($edge)||!isset($edge['data'])||!is_array($edge['data'])||!array_is_list($edge['data']))throw new RuntimeException('Meta hierarchy edge is malformed.');
        $items=[];$ids=[];$seen=[];$pages=0;
        while(true){
            if(++$pages>100)throw new RuntimeException('Meta nested edge page limit; partial inventory rejected.');
            foreach($edge['data'] as $item){
                if(!is_array($item)||!isset($item['id'])||!is_string($item['id'])&&!is_int($item['id'])||(string)$item['id']===''||isset($ids[(string)$item['id']]))throw new RuntimeException('Meta missing or duplicate nested object ID.');
                $item['id']=$this->objectId($item['id']);$ids[$item['id']]=true;$items[]=$item;
            }
            $next=$this->nextPage($edge);
            if($next===null)break;
            $this->validateHierarchyPageUrl($next,"/{$this->apiVersion}/{$parent}/{$name}");
            if(isset($seen[$next])||++$requests>1000)throw new RuntimeException('Meta nested edge cycle or request limit; partial inventory rejected.');
            $seen[$next]=true;
            $response=$this->executeWithTokenHandling($account,fn()=>Http::timeout(30)->connectTimeout(10)->withoutRedirecting()->withToken($account->access_token)->get($next),"meta:{$parent}/{$name}");
            if($response->failed())throw new RuntimeException('Meta nested hierarchy request failed.');
            $edge=$response->json();
            if(!is_array($edge)||!isset($edge['data'])||!is_array($edge['data'])||!array_is_list($edge['data']))throw new RuntimeException('Meta nested hierarchy response is malformed.');
            $rawResponses[]=$edge;
        }
        return ['data'=>$items];
    }

    private function validateHierarchyPageUrl(mixed $url,string $path):void
    {
        if(!is_string($url))throw new RuntimeException('Meta hierarchy paging URL malformed.');
        $p=parse_url($url);
        if(!$p||($p['scheme']??null)!=='https'||($p['host']??null)!=='graph.facebook.com'||($p['path']??null)!==$path||isset($p['port'])||isset($p['user'])||isset($p['pass'])||isset($p['fragment']))throw new RuntimeException('Meta hierarchy paging URL is outside the expected provider edge.');
    }

    public function fetchInsights(ConnectedAdAccount $account, string $startDate, string $endDate): array
    {
        $actId = str_starts_with($account->external_account_id, 'act_')
            ? $account->external_account_id
            : 'act_' . $account->external_account_id;

        $metrics = [];
        $rawResponses = [];
        $recordedPages = [];
        $recordedBytes = 0;
        $nextUrl = "https://graph.facebook.com/{$this->apiVersion}/{$actId}/insights";
        $params = [
            'level' => 'campaign',
            'fields' => 'campaign_id,campaign_name,spend,impressions,reach,frequency,clicks,actions,action_values',
            'time_range' => json_encode(['since' => $startDate, 'until' => $endDate]),
            'time_increment' => 1,
            'limit' => 200,
        ];
        $pageCount = 0;
        $maxPages = 100;
        $seenPages=[];$rowKeys=[];

        do {
            $pageCount++;
            if ($pageCount > $maxPages) {
                throw new RuntimeException('Meta insight page limit; partial report rejected.');
            }

            $this->validateHierarchyPageUrl($nextUrl,"/{$this->apiVersion}/{$actId}/insights");
            if(isset($seenPages[$nextUrl]))throw new RuntimeException('Meta insight page cycle; partial report rejected.');
            $seenPages[$nextUrl]=true;
            $response = $this->executeWithTokenHandling($account, function () use ($account, $nextUrl, $params) {
                return Http::timeout(30)->connectTimeout(10)->withoutRedirecting()
                    ->withToken($account->access_token)
                    ->get($nextUrl, $params);
            }, "meta:{$actId}/insights");

            if ($response->failed()) {
                throw new RuntimeException("Failed to fetch Meta insights: " . $response->body());
            }

            // Original reporting bytes before JSON decoding or float conversion. Evidence only, not a qualified import contract.
            $body = $response->body();
            $recordedBytes += strlen($body);
            if ($recordedBytes > 16 * 1024 * 1024 || strlen($body) > 8 * 1024 * 1024) {
                throw new RuntimeException('Meta original reporting page exceeds evidence capture limit; no partial import.');
            }
            $recordedPages[] = ['body' => $body, 'sha256' => hash('sha256', $body),
                'request' => ['endpoint' => "/{$actId}/insights", 'act_id' => $actId, 'api_version' => $this->apiVersion, 'level' => 'campaign',
                    'fields' => \App\Services\Advertising\Sync\MetaNativeRecordedMetrics::FIELDS, 'time_increment' => 1,
                    'time_range' => ['since' => $startDate, 'until' => $endDate], 'page_index' => $pageCount],
                'account_external_id' => $account->external_account_id, 'currency' => $account->currency, 'timezone' => $account->timezone,
                'start_date' => $startDate, 'end_date' => $endDate, 'retrieved_at' => now()->toIso8601String(),
                'request_id' => $response->header('x-fb-trace-id'), 'status' => $response->status(),
                'precision_state' => 'original_body_retained_not_yet_qualified'];
            $raw = $response->json();
            $rawResponses[] = $raw;

            foreach ($this->pageItems($raw) as $item) {
                if(!is_array($item)||!isset($item['campaign_id'],$item['date_start'])||(!is_string($item['campaign_id'])&&!is_int($item['campaign_id']))||!is_string($item['date_start']))throw new RuntimeException('Meta insight entity/date malformed.');
                $item['campaign_id']=$this->objectId($item['campaign_id']);
                $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$item['date_start']);
                if(!$date||$date->format('Y-m-d')!==$item['date_start']||$item['date_start']<$startDate||$item['date_start']>$endDate||isset($item['date_stop'])&&$item['date_stop']!==$item['date_start'])throw new RuntimeException('Meta daily report date invalid or aggregated.');
                $key=json_encode([$item['campaign_id'],$item['date_start']],JSON_THROW_ON_ERROR);
                if(isset($rowKeys[$key]))throw new RuntimeException('Meta repeated campaign/day across report pages.');
                $rowKeys[$key]=true;
                // A single documented purchase action, never grouped aliases plus components/leads.
                // Absent data remains unknown; the shared importer refuses unknown required outcomes.
                $conversions = $this->exactPurchaseAction($item['actions'] ?? null);
                $conversionValue = $this->exactPurchaseAction($item['action_values'] ?? null);

                $metrics[] = [
                    'external_campaign_id' => (string) ($item['campaign_id'] ?? ''),
                    'external_ad_set_id' => null,
                    'external_ad_id' => null,
                    'date' => $item['date_start'],
                    'spend' => $this->metricOperand($item,'spend'),
                    'impressions' => $this->metricOperand($item,'impressions',true),
                    'reach' => $this->metricOperand($item,'reach',true),
                    'frequency' => $this->metricOperand($item,'frequency'),
                    'clicks' => $this->metricOperand($item,'clicks',true),
                    'conversions' => $conversions,
                    'conversion_value' => $conversionValue,
                    'metric_definition' => [
                        'version'=>'meta-pixel-purchase-1',
                        'normalization'=>'strict-meta-operands-1',
                        'account_metadata_source'=>$this->hasExplicitAccountMetadata($account)?'meta-discovery-explicit-1':'unverified_legacy_or_missing',
                        'conversion'=>'offsite_conversion.fb_pixel_purchase',
                        'value'=>'offsite_conversion.fb_pixel_purchase',
                        'counting'=>'provider_reported_actions_not_unique_people',
                        'attribution'=>'provider_default_unverified_not_cross_platform_comparable',
                        'date_basis'=>'provider_default_unverified',
                        'reporting_timezone'=>$account->timezone,
                        'missing_action'=>'unknown_not_zero',
                        'aliases_combined'=>false,
                    ],
                ];
            }

            $nextUrl = $this->nextPage($raw);
            $params = [];
        } while ($nextUrl!==null);

        return [
            'endpoint' => "/{$actId}/insights",
            'raw' => $rawResponses,
            'metrics' => $metrics,
            'recorded_response_pages' => $recordedPages,
        ];
    }

    private function hasExplicitAccountMetadata(ConnectedAdAccount $a):bool
    {
        $m=$a->metadata['metadata_source']??null;
        return is_array($m)&&($m['version']??null)==='meta-discovery-explicit-1'&&($m['currency']??null)===$a->currency&&($m['timezone']??null)===$a->timezone&&is_string($a->currency)&&preg_match('/^[A-Z]{3}$/D',$a->currency)&&in_array($a->timezone,\DateTimeZone::listIdentifiers(),true);
    }

    private function objectId(mixed $id):string
    {
        if((!is_string($id)&&!is_int($id))||trim((string)$id)===''||trim((string)$id)!==(string)$id||strlen((string)$id)>255||!preg_match('/^[A-Za-z0-9_.:-]+$/D',(string)$id))throw new RuntimeException('Meta object ID malformed.');
        return (string)$id;
    }

    /** Unknown remains null. Finite nonnegative decimal operands only; never PHP coercion. */
    private function metricOperand(array $row,string $key,bool $integer=false):int|float|null
    {
        return \App\Services\Advertising\MetricOperand::read($row,$key,$integer,1,true);
    }

    private function pageItems(mixed $page):array
    {
        if(!is_array($page)||!isset($page['data'])||!is_array($page['data'])||!array_is_list($page['data']))throw new RuntimeException('Meta paginated response malformed; partial report rejected.');
        return $page['data'];
    }
    private function nextPage(array $page):?string
    {
        if(!array_key_exists('paging',$page))return null;
        if(!is_array($page['paging']))throw new RuntimeException('Meta paging descriptor malformed.');
        if(!array_key_exists('next',$page['paging']))return null;
        $next=$page['paging']['next'];
        if(!is_string($next)||$next==='')throw new RuntimeException('Meta next page is not an explicit nonempty URL.');
        return $next;
    }

    private function exactPurchaseAction(mixed $actions):?float
    {
        if($actions===null)return null;
        if(!is_array($actions)||!array_is_list($actions))throw new RuntimeException('Meta action collection malformed.');
        $result=null;$found=false;
        foreach($actions as$action){
            if(!is_array($action)||!isset($action['action_type'])||!is_string($action['action_type']))throw new RuntimeException('Meta action descriptor malformed.');
            if($action['action_type']!=='offsite_conversion.fb_pixel_purchase')continue;
            $v=$action['value']??null;
            if($found||is_bool($v)||!is_numeric($v)||!is_finite((float)$v)||(float)$v<0)throw new RuntimeException('Meta purchase action duplicate or malformed.');
            $found=true;$result=$this->metricOperand(['selected_action'=>$v],'selected_action');
        }
        return $result;
    }

    protected function executeWithTokenHandling(ConnectedAdAccount $account, callable $callback, string $endpoint): Response
    {
        $response = $this->executeHttp($callback, $endpoint);

        $fbErrorCode = $response->json('error.code');
        $isTokenExpired = ($response->status() === 401 || $fbErrorCode === 190);

        if ($isTokenExpired) {
            $account->update(['status' => 'token_expired']);
            Log::warning("Meta access token expired for account #{$account->id}. Marked token_expired.");
            throw new OAuthTokenExpiredException("Meta OAuth token is expired or invalidated. User must re-authenticate.", 'meta', $account->id);
        }

        return $response;
    }

    protected function executeHttp(callable $call, string $endpoint): Response
    {
        $start = microtime(true);
        $attempts = 0;
        $maxAttempts = 3;
        $delayMs = 200;

        while (true) {
            $attempts++;
            try {
                /** @var Response $response */
                $response = $call();

                $fbCode = $response->json('error.code');
                $isRateLimit = ($response->status() === 429 || in_array($fbCode, [4, 17, 613], true));

                if ($isRateLimit) {
                    if ($attempts >= $maxAttempts) {
                        Log::warning("Meta Ads rate limit hit after {$attempts} attempts", [
                            'endpoint' => $endpoint,
                            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
                        ]);
                        throw new ProviderRateLimitException("Meta Ads rate limit reached.", 'meta');
                    }
                    usleep($delayMs * 1000);
                    $delayMs *= 2;
                    continue;
                }

                if ($response->serverError() && $attempts < $maxAttempts) {
                    usleep($delayMs * 1000);
                    $delayMs *= 2;
                    continue;
                }

                return $response;
            } catch (ConnectionException $e) {
                if ($attempts >= $maxAttempts) {
                    Log::error("Meta network connection error", [
                        'endpoint' => $endpoint,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
                usleep($delayMs * 1000);
                $delayMs *= 2;
            }
        }
    }

    protected function ensureConfigured(): void
    {
        if (!$this->config || empty($this->config->app_id) || empty($this->config->app_secret)) {
            throw new RuntimeException("Meta Marketing API credentials have not been configured.");
        }
    }
}
