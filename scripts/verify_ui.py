import asyncio
from playwright.async_api import async_playwright

async def verify_platform():
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True)
        context = await browser.new_context(viewport={'width': 1440, 'height': 900})
        page = await context.new_page()

        # 1. Login
        print("Navigating to login...")
        await page.goto('http://localhost:4200/login', wait_until='networkidle')
        await page.fill('input[type="email"]', 'admin@adplatform.local')
        await page.fill('input[type="password"]', 'AdminSecurePassword2026!')
        await page.click('button[type="submit"]')
        await page.wait_for_url('**/dashboard', timeout=15000)
        await page.wait_for_timeout(1000)
        await page.screenshot(path=r'C:\Users\Mohamed HatemMubarak\.gemini\antigravity\brain\a16943eb-ac8e-4b89-bcd3-c9074bf20b4a\final_dashboard_view.png')
        print("Captured Dashboard")

        # 2. Campaigns Page
        await page.goto('http://localhost:4200/campaigns', wait_until='networkidle')
        await page.wait_for_timeout(800)
        await page.screenshot(path=r'C:\Users\Mohamed HatemMubarak\.gemini\antigravity\brain\a16943eb-ac8e-4b89-bcd3-c9074bf20b4a\final_campaigns_view.png')
        print("Captured Campaigns")

        # 3. Operations Research
        await page.goto('http://localhost:4200/operations-research', wait_until='networkidle')
        await page.wait_for_timeout(1200)
        await page.screenshot(path=r'C:\Users\Mohamed HatemMubarak\.gemini\antigravity\brain\a16943eb-ac8e-4b89-bcd3-c9074bf20b4a\final_operations_research_view.png')
        print("Captured Operations Research")

        # 4. AI Center (Single button view)
        await page.goto('http://localhost:4200/ai-center', wait_until='networkidle')
        await page.wait_for_timeout(1000)
        await page.screenshot(path=r'C:\Users\Mohamed HatemMubarak\.gemini\antigravity\brain\a16943eb-ac8e-4b89-bcd3-c9074bf20b4a\final_ai_model_hub_clean.png')
        print("Captured AI Model Hub")

        # 5. Developer Settings (Looker Studio)
        await page.goto('http://localhost:4200/developer-settings', wait_until='networkidle')
        await page.wait_for_timeout(1000)
        await page.screenshot(path=r'C:\Users\Mohamed HatemMubarak\.gemini\antigravity\brain\a16943eb-ac8e-4b89-bcd3-c9074bf20b4a\final_developer_looker_view.png')
        print("Captured Developer & Looker")

        # 6. Team & Access
        await page.goto('http://localhost:4200/team', wait_until='networkidle')
        await page.wait_for_timeout(1000)
        await page.screenshot(path=r'C:\Users\Mohamed HatemMubarak\.gemini\antigravity\brain\a16943eb-ac8e-4b89-bcd3-c9074bf20b4a\final_team_view.png')
        print("Captured Team View")

        await browser.close()
        print("Verification completed successfully!")

if __name__ == '__main__':
    asyncio.run(verify_platform())
