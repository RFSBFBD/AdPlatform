# Looker Studio Community Connector Setup Guide

**Advertising Management Platform — Multi-Channel Connector**  
**Supported Channels:** Meta Ads, Google Ads, TikTok Ads  
**Last Updated:** 2026-11-18  

---

## 1. Overview
The Advertising Management Platform exposes a dedicated reporting API designed for direct ingestion into Google Looker Studio without third-party connector fees or aggregators.

- **API Endpoint:** `GET /api/v1/reporting/looker-studio`
- **Authentication:** Sanctum API Bearer Token (Generated in Developer Settings)
- **Script Location:** `looker-studio/connector.js`

---

## 2. Step-by-Step Installation

### Step 1: Open Google Apps Script
1. Navigate to [script.google.com](https://script.google.com).
2. Click **New Project** and name it `AdPlatform Looker Connector`.
3. In `Code.gs`, replace all placeholder code with the contents of `looker-studio/connector.js`.

### Step 2: Configure Manifest (`appsscript.json`)
1. In Apps Script settings, check **"Show 'appsscript.json' manifest file in editor"**.
2. Replace `appsscript.json` with:
```json
{
  "timeZone": "Etc/GMT",
  "dependencies": {},
  "exceptionLogging": "STACKDRIVER",
  "runtimeVersion": "V8",
  "dataStudio": {
    "name": "Advertising Management Platform",
    "logoUrl": "https://raw.githubusercontent.com/adplatform/brand/main/logo.png",
    "company": "AdPlatform",
    "companyUrl": "https://adplatform.local",
    "addonUrl": "https://adplatform.local/docs",
    "supportUrl": "https://adplatform.local/support",
    "description": "Multi-channel paid advertising analytics for Meta, Google, and TikTok Ads."
  }
}
```

### Step 3: Deploy Deployment
1. Click **Deploy** > **Test Deployments**.
2. Select type: **Looker Studio Community Connector**.
3. Copy the test deployment link or click **Open in Looker Studio**.

### Step 4: Authorize and Connect
1. In Looker Studio, enter your API token generated from the platform (`Bearer <token>`).
2. Specify the API Base URL (e.g. `https://api.yourdomain.com` or local tunnel).
3. Click **Connect** to ingest all dimensions (Date, Platform, Account, Campaign) and metrics (Spend, Impressions, Clicks, Conversions, ROAS, CPC).
