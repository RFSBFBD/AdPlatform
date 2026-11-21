import asyncio
from playwright.async_api import async_playwright

async def capture_loading_page():
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True)
        context = await browser.new_context(viewport={'width': 1440, 'height': 900})
        page = await context.new_page()

        # Login
        await page.goto('http://localhost:4200/login', wait_until='networkidle')
        await page.fill('input[type="email"]', 'admin@adplatform.local')
        await page.fill('input[type="password"]', 'AdminSecurePassword2026!')
        await page.click('button[type="submit"]')
        await page.wait_for_url('**/dashboard', timeout=15000)
        await page.wait_for_timeout(1000)

        # Trigger navigation to Operations Research and capture MID-TRANSITION loading screen
        print("Clicking Operations Research nav link...")
        await page.click('a[href="/operations-research"]')
        # Wait 200ms into the transition to capture the full loading overlay in action
        await page.wait_for_timeout(220)
        await page.screenshot(path=r'C:\Users\Mohamed HatemMubarak\.gemini\antigravity\brain\a16943eb-ac8e-4b89-bcd3-c9074bf20b4a\loading_page_in_action.png')
        print("Captured Loading Page In Action!")

        # Also wait until loaded and toggle to dark mode to test loading in dark mode
        await page.wait_for_url('**/operations-research', timeout=10000)
        await page.wait_for_timeout(800)

        # Toggle dark mode
        await page.click('.theme-switch__container')
        await page.wait_for_timeout(500)

        # Click Campaigns nav link and capture dark mode loading screen
        print("Clicking Campaigns nav link in dark mode...")
        await page.click('a[href="/campaigns"]')
        await page.wait_for_timeout(220)
        await page.screenshot(path=r'C:\Users\Mohamed HatemMubarak\.gemini\antigravity\brain\a16943eb-ac8e-4b89-bcd3-c9074bf20b4a\loading_page_dark_in_action.png')
        print("Captured Dark Loading Page In Action!")

        await browser.close()

if __name__ == '__main__':
    asyncio.run(capture_loading_page())
