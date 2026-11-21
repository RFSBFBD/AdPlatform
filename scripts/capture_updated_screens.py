import os
import time
from playwright.sync_api import sync_playwright

ARTIFACT_DIR = r"C:\Users\Mohamed HatemMubarak\.gemini\antigravity\brain\a16943eb-ac8e-4b89-bcd3-c9074bf20b4a"

def capture_updated_screens():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1440, "height": 900})
        page = context.new_page()

        # 1. Login
        page.goto("http://localhost:4200/login")
        page.wait_for_load_state("networkidle")
        page.fill('input[type="email"]', "admin@adplatform.local")
        page.fill('input[type="password"]', "AdminSecurePassword2026!")
        page.click('button[type="submit"]')
        page.wait_for_url("**/dashboard", timeout=10000)
        page.wait_for_load_state("networkidle")

        # 2. Campaigns Page & Select Dropdown
        page.goto("http://localhost:4200/campaigns")
        page.wait_for_load_state("networkidle")
        time.sleep(1)
        page.screenshot(path=os.path.join(ARTIFACT_DIR, "updated_campaigns_dropdown.png"), full_page=True)

        # 3. AI Center - Settings Tab with Universal Model Hub
        page.goto("http://localhost:4200/ai-center")
        page.wait_for_load_state("networkidle")
        time.sleep(1)
        # Click on Settings Tab
        page.click('text="AI Model API Config & Test"')
        time.sleep(1)
        page.screenshot(path=os.path.join(ARTIFACT_DIR, "updated_ai_model_hub.png"), full_page=True)

        # 4. Developer Settings with Official Social Logos & Looker Studio
        page.goto("http://localhost:4200/developer-settings")
        page.wait_for_load_state("networkidle")
        time.sleep(1)
        page.screenshot(path=os.path.join(ARTIFACT_DIR, "updated_developer_and_looker.png"), full_page=True)

        browser.close()
        print("Updated screenshots captured!")

if __name__ == "__main__":
    capture_updated_screens()
