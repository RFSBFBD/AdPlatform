# Walmart Connect Sponsored Search: blocked integration boundary

No live-ready adapter, credential exchange or connection button is claimed. This is not Walmart Marketplace or Data Ventures.

Official references checked2026-11-23:
- https://developer.walmart.com/advertising-partners-search/docs/steps-for-technology-providers-and-technology-enabled-agencies
- https://developer.walmart.com/advertising-partners-search/docs/steps-for-api-partners-to-gain-account-access
- https://developer.walmart.com/advertising-partners-search/docs/create-advertiser-attributes-snapshot
- https://developer.walmart.com/advertising-partners-search/docs/retrieve-snapshots
- https://developer.walmart.com/advertising-partners-search/docs/advertiser-attributes-snapshot-overview

The approved partner onboarding supplies auth_token and ConsumerId and calls for public-key integration. Requests require Bearer token plus WM_CONSUMER.ID, WM_SEC.AUTH_SIGNATURE, WM_SEC.KEY_VERSION=1 and WM_CONSUMER.intimestamp. Retrieved public Connect docs refer to a signature generator but do not establish canonical bytes, signed header ordering, padding/hash contract or token issuance/refresh schema. Therefore no signer is invented.

The independently documented advertiser discovery creates advertiserAttributesV2 via POST /api/v1/snapshot/insight, then polls GET /api/v1/snapshot?snapshotId=... and downloads compressed JSON. Production service base is https://developer.api.walmart.com/api-proxy/service/WPA/Api/v1/ so the operation's own api/v1 path remains present. Create is an object, retrieve is documented as an array. Public retrieve examples contain invalid JSON syntax and disagree on advertiserId versus advertiserID, organizationId versus organizationID, and grantTimeStamp versus grantTimestamp. These are documentation conflicts, not observed wire fixtures. No timezone or currency contract was established. Snapshot ID validity24h does not establish file URL TTL or access-token expiry.

To unblock: obtain the actual Connect partner signer and auth-token lifecycle contract through approved onboarding, verify wire responses in a sandbox, and establish currency/timezone and reporting schemas. No account/application setup was performed in this work.
