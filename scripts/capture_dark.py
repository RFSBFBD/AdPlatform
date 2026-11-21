

import asyncio
from playwright.async_api import async_playwright

async def dark_capture():
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True)
        context = await browser.new_context(viewport={'width': 1440, 'height': 900})
        page = await context.new_page()

        await page.goto('http://localhost:4200/login', wait_until='networkidle')
        await page.fill('input[type="email"]', 'admin@adplatform.local')
        await page.fill('input[type="password"]', 'AdminSecurePassword2026!')
        await page.click('button[type="submit"]')
        await page.wait_for_url('**/dashboard', timeout=15000)

        # Toggle to dark theme
        await page.click('.theme-switch__container')
        await page.wait_for_timeout(800)
        await page.screenshot(path=r'C:\Users\Mohamed HatemMubarak\.gemini\antigravity\brain\a16943eb-ac8e-4b89-bcd3-c9074bf20b4a\final_dashboard_dark.png')
        print("Captured Dashboard Dark")

        # Operations Research dark
        await page.goto('http://localhost:4200/operations-research', wait_until='networkidle')
        await page.wait_for_timeout(1200)
        await page.screenshot(path=r'C:\Users\Mohamed HatemMubarak\.gemini\antigravity\brain\a16943eb-ac8e-4b89-bcd3-c9074bf20b4a\final_operations_research_dark.png')
        print("Captured Operations Research Dark")

        # Campaigns dark
        await page.goto('http://localhost:4200/campaigns', wait_until='networkidle')
        await page.wait_for_timeout(800)
        await page.screenshot(path=r'C:\Users\Mohamed HatemMubarak\.gemini\antigravity\brain\a16943eb-ac8e-4b89-bcd3-c9074bf20b4a\final_campaigns_dark.png')
        print("Captured Campaigns Dark")

        await browser.close()

if __name__ == '__main__':
    asyncio.run(dark_capture())
