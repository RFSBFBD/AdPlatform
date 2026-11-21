# Google Looker Studio Community Connector Deployment Guide

This document describes how to deploy the official Advertising Management Platform Community Connector to Google Apps Script and connect it to Google Looker Studio.

---

## 1. Prerequisites
- A Google account with access to [Google Apps Script](https://script.google.com).
- A running, public HTTPS endpoint of the Advertising Management Platform backend (e.g., `https://api.yourdomain.com`).
- A personal access API token generated from the platform (`POST /api/v1/auth/login` or Developer Settings).

---

## 2. Step-by-Step Deployment

### Step 2.1: Create Apps Script Project
1. Navigate to [script.google.com](https://script.google.com) and click **New Project**.
2. Rename the project to `Advertising Management Platform Connector`.

### Step 2.2: Add Connector Code
1. Replace the contents of `Code.gs` with the contents of `connector.js`.
2. Click **Project Settings** (gear icon) on the left sidebar.
3. Check the box **"Show 'appsscript.json' manifest file in editor"**.
4. Return to the editor, open `appsscript.json`, and paste the contents of `appsscript.json`.
5. Save all files (`Ctrl + S` or the disk icon).

### Step 2.3: Test Deployment
1. Click **Deploy** -> **Test deployments**.
2. Select **Looker Studio Community Connector**.
3. Click the generated test link (opens Looker Studio data source configuration).

### Step 2.4: Production Deployment
1. Click **Deploy** -> **New deployment**.
2. Select type: **Looker Studio Community Connector**.
3. Enter description: `v1.0 Production Release`.
4. Click **Deploy**. Copy the **Deployment ID**.

---

## 3. Configuring Looker Studio Report

1. In Looker Studio, enter the platform API key (Sanctum bearer token) in the authorization prompt.
2. In the configuration screen:
   - **API Base URL**: `https://api.yourdomain.com` (leave blank to default to `http://localhost:8000` during local tunneling).
3. Select your desired date range.
4. Click **Connect**.
5. Add Dimensions:
   - `Date`
   - `Advertising Platform`
   - `Account Name`
   - `Campaign Name`
   - `Currency`
6. Add Metrics:
   - `Spend`
   - `Impressions`
   - `Clicks`
   - `Conversions`
   - `Conversion Value`
   - `Average CPC`
   - `ROAS`
7. Click **Create Report**.
