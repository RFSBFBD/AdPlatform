import os
import time
from playwright.sync_api import sync_playwright

ARTIFACT_DIR = r"C:\Users\Mohamed HatemMubarak\.gemini\antigravity\brain\a16943eb-ac8e-4b89-bcd3-c9074bf20b4a"
os.makedirs(ARTIFACT_DIR, exist_ok=True)

def run_tests():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 1440, "height": 900})
        page = context.new_page()

        print("[1/5] Navigating to Login Page...")
        page.goto("http://localhost:4200/login")
        page.wait_for_load_state("networkidle")
        time.sleep(1)

        login_screenshot = os.path.join(ARTIFACT_DIR, "login_page.png")
        page.screenshot(path=login_screenshot, full_page=True)
        print(f"Login screenshot saved: {login_screenshot}")

        # Fill credentials
        print("[2/5] Filling login credentials...")
        page.fill('input[type="email"]', "admin@adplatform.local")
        page.fill('input[type="password"]', "AdminSecurePassword2026!")
        page.click('button[type="submit"]')

        # Wait for navigation to dashboard
        page.wait_for_url("**/dashboard", timeout=10000)
        page.wait_for_load_state("networkidle")
        time.sleep(2)

        dashboard_screenshot = os.path.join(ARTIFACT_DIR, "dashboard_page.png")
        page.screenshot(path=dashboard_screenshot, full_page=True)
        print(f"Dashboard screenshot saved: {dashboard_screenshot}")

        # Navigate to AI Chatbot
        print("[3/5] Navigating to AI Chatbot...")
        page.goto("http://localhost:4200/ai-chat")
        page.wait_for_load_state("networkidle")
        time.sleep(1.5)

        ai_chat_screenshot = os.path.join(ARTIFACT_DIR, "ai_chat_page.png")
        page.screenshot(path=ai_chat_screenshot, full_page=True)
        print(f"AI Chat screenshot saved: {ai_chat_screenshot}")

        # Navigate to Connected Accounts
        print("[4/5] Navigating to Connected Accounts...")
        page.goto("http://localhost:4200/accounts")
        page.wait_for_load_state("networkidle")
        time.sleep(1.5)

        accounts_screenshot = os.path.join(ARTIFACT_DIR, "accounts_page.png")
        page.screenshot(path=accounts_screenshot, full_page=True)
        print(f"Accounts screenshot saved: {accounts_screenshot}")

        # Navigate to Developer Settings
        print("[5/5] Navigating to Developer Settings...")
        page.goto("http://localhost:4200/developer-settings")
        page.wait_for_load_state("networkidle")
        time.sleep(1.5)

        developer_screenshot = os.path.join(ARTIFACT_DIR, "developer_settings_page.png")
        page.screenshot(path=developer_screenshot, full_page=True)
        print(f"Developer Settings screenshot saved: {developer_screenshot}")

        browser.close()
        print("ALL TESTS AND SCREENSHOTS COMPLETED SUCCESSFULLY!")

if __name__ == "__main__":
    run_tests()
