<?php
return [
    // Deployment-approved exact HTTPS download hosts from its vendor contract.
    // Empty by default: never forward OAuth credentials or trust arbitrary poll URLs.
    'microsoft_download_hosts'=>array_values(array_filter(array_map('trim',explode(',',(string)env('MICROSOFT_REPORT_DOWNLOAD_HOSTS',''))))),
    'amazon_download_hosts'=>array_values(array_filter(array_map('trim',explode(',',(string)env('AMAZON_REPORT_DOWNLOAD_HOSTS',''))))),
    'amazon_end_date_semantics'=>env('AMAZON_REPORT_END_DATE_SEMANTICS'), // Must be verified: inclusive or exclusive.
    'microsoft_poll_seconds'=>120,
    'microsoft_timezone_map'=>[], // Provider enum => verified IANA mapping supplied by deployment.
];
