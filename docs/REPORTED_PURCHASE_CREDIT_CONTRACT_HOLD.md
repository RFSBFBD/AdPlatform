# Reported purchase-credit portfolio: source-contract hold

Checkpoint after5d17025, 23 November 2026. The proposed Meta+Snapchat reported-purchase-credit allocation branch is NOT implemented. No new eligibility marker, UI, actual scenario, live account request or package was added. Existing global outcome guards remain. This hold does not imply contrary evidence about the monetary unit; it records what remains insufficiently grounded for this exact branch.

## Snapchat dimensions established by primary documentation

Inspected https://developers.snap.com/marketing-api/Ads-API/measurement :

- conversion_purchases_value is attributed PURCHASE value in microcurrency of the ad-account currency.1000000microcurrency equals1local currency unit.
- action_report_time explicitly selects conversion-event time or impression time. DAY boundaries use account timezone.
- conversion_data_processed_end_time identifies conversion-time processing completion. The generic48hour statement is not a replacement for this returned watermark.

A future typed route should send an explicit date policy, validate account/day wrapper identity and boundaries, retain the request policy and returned processing watermark, and require the included conversion days to be covered. No existing historical marker or fixed-day cutoff is upgraded by this research.

## Meta precise unresolved dimension

The exact returned action_values purchase-window subfield's monetary denomination and currency-conversion rule are not explicitly established in the primary pages retrieved. Purchase event input value/currency does not by itself prove returned Insights currency/account denomination. The presence of account_currency describes the account but does not independently prove the monetary field's transformation.

Primary sources inspected:

- https://developers.facebook.com/docs/meta-pixel/implementation/conversion-tracking/ establishes the website pixel purchase action, monetary input value and input event currency.
- https://developers.facebook.com/docs/marketing-api/reference/ads-action-stats/ defines default value and explicit attribution-window fields, but does not state monetary denomination/currency.
- https://developers.facebook.com/docs/graph-api/reference/adaccount/insights/ documents account_currency, action_values and available request policies.
- https://developers.facebook.com/docs/marketing-api/reference/ad-campaign/insights/ documents action_report_time and attribution toggles. Retrieved sections have differing default-window descriptions, so no default is assumed.
- https://developers.facebook.com/docs/marketing-api/insights/best-practices/ explicitly places reporting in account timezone.

A future route cannot merely add action_attribution_windows then continue parsing generic value as though it necessarily uses that requested window. It should select one documented window and read that exact returned subfield, refusing omission. Overlapping windows must not be summed. That design still needs the exact money contract above.

## Corroboration, not optimizer eligibility

Fetched connector documentation corroborates account-currency conversion-value reporting:

- https://help.clarisights.com/article/integrating-facebook-ads-on-clarisights explicitly describes pulling spend/conversion values in account currency, then converting to reporting currency, with native amounts separate.
- https://docs.zernio.com/platforms/meta-ads/analytics associates action_values with ad-account currency and the same units as spend.
- https://docs.adriel.com/data-sources/g-n/meta-ads/data-reference describes conversion-value currency metrics, but its mixed mappings are not adopted.

These are operator documentation, not a precise vendor contract for our exact returned field/window. They are not discarded, nor promoted to independently verified API truth. Their alias-selection and pooling behavior is not adopted.

## What would settle the hold

A precise vendor field/window monetary contract, or a separately authorized reconciliation tying known event currency/value, returned account_currency and exact-window output for the relevant API version. A user equivalence checkbox, generic account login or synthetic fixture is not that evidence. No owner access or imaginary budget is requested just to continue development.

The original portfolio outcome remains open.5d17025 remains the last verified code milestone:531backend tests4169assertions,139frontend tests/build. The current delivered replacement archive is unchanged. Independent numerical/model validation and improvements to already-supported same-source workflows can proceed separately, without claiming this blocked raw-fixture-to-cross-platform-success path was completed.
