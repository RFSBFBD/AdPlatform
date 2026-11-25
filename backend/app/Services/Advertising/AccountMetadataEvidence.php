<?php
namespace App\Services\Advertising;
use App\Models\ConnectedAdAccount;
/** Returned account fields, not independent currency/attribution verification. */
final class AccountMetadataEvidence {
 public const VERSION='provider-discovery-explicit-1';
 public static function marker(ConnectedAdAccount $a):string {
  $m=$a->metadata['metadata_source']??null;
  return is_array($m)&&($m['version']??null)===self::VERSION&&($m['currency']??null)===$a->currency&&($m['timezone']??null)===$a->timezone&&is_string($a->currency)&&preg_match('/^[A-Z]{3}$/D',$a->currency)&&in_array($a->timezone,\DateTimeZone::listIdentifiers(),true)?self::VERSION:'unverified_legacy_or_missing';
 }
}
