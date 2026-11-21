import os
import time
from playwright.sync_api import sync_playwright

ARTIFACT_DIR = r"C:\Users\Mohamed HatemMubarak\.gemini\antigravity\brain\a16943eb-ac8e-4b89-bcd3-c9074bf20b4a"

def verify_latest_changes():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1440, "height": 900})
        page = context.new_page()

        # Login
        page.goto("http://localhost:4200/login")
        page.wait_for_load_state("networkidle")
        page.fill('input[type="email"]', "admin@adplatform.local")
        page.fill('input[type="password"]', "AdminSecurePassword2026!")
        page.click('button[type="submit"]')
        page.wait_for_url("**/dashboard", timeout=10000)
        page.wait_for_load_state("networkidle")
        time.sleep(1)

        # 1. Capture Dashboard showing cleaned sidebar and improved theme switch in topbar
        page.screenshot(path=os.path.join(ARTIFACT_DIR, "dashboard_theme_and_nav.png"), full_page=True)

        # 2. Go to AI Center and click Connected AI APIs tab
        page.goto("http://localhost:4200/ai-center")
        page.wait_for_load_state("networkidle")
        page.click('text="Connected AI APIs"')
        time.sleep(1)
        page.screenshot(path=os.path.join(ARTIFACT_DIR, "ai_center_clean_view.png"), full_page=True)

        # 3. Click Add AI API button to open the clean modal
        page.click('text="Add AI API (أضف API)"')
        time.sleep(1)
        page.screenshot(path=os.path.join(ARTIFACT_DIR, "ai_add_api_modal.png"), full_page=True)

        browser.close()
        print("Latest verification screenshots captured successfully!")

if __name__ == "__main__":
    verify_latest_changes()
