import { Component, inject, OnDestroy, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, NavigationStart, NavigationEnd, NavigationCancel, NavigationError } from '@angular/router';
import { Subscription } from 'rxjs';

@Component({
  selector: 'app-route-progress-bar',
  standalone: true,
  imports: [CommonModule],
  template: `
    @if (isActive()) {
      <!-- Full-Screen High-Tech Route Loading Page / Overlay -->
      <div class="route-loading-page" [class.fading-out]="isFadingOut()">
        <!-- Ambient Cyber Background Grid -->
        <div class="ambient-glow"></div>
        <div class="cyber-grid-overlay"></div>

        <!-- Top Glowing Laser Progress Bar -->
        <div class="top-laser-track">
          <div class="laser-beam" [style.width.%]="progress()">
            <div class="laser-flare"></div>
          </div>
        </div>

        <!-- Central Loading Stage -->
        <div class="loading-stage-box">
          <div class="orb-scanner-stage">
            <!-- Outer Pulsing Rings -->
            <div class="scanner-ring outer-ring"></div>
            <div class="scanner-ring inner-ring"></div>
            <div class="scanner-particles"></div>
            
            <!-- Platform Brand Icon / Emblem -->
            <div class="core-emblem-badge">
              <svg width="48" height="48" viewBox="0 0 64 64" fill="none" class="emblem-svg">
                <defs>
                  <linearGradient id="route-load-grad" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stop-color="#6366f1" />
                    <stop offset="50%" stop-color="#38bdf8" />
                    <stop offset="100%" stop-color="#10b981" />
                  </linearGradient>
                  <filter id="badge-glow" x="-20%" y="-20%" width="140%" height="140%">
                    <feGaussianBlur stdDeviation="3" result="blur" />
                    <feComposite in="SourceGraphic" in2="blur" operator="over" />
                  </filter>
                </defs>
                <path d="M14 44 L26 30 L38 38 L50 18" stroke="url(#route-load-grad)" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" filter="url(#badge-glow)" />
                <circle cx="14" cy="44" r="5" fill="#6366f1" stroke="#ffffff" stroke-width="1.8" />
                <circle cx="26" cy="30" r="4.5" fill="#38bdf8" stroke="#ffffff" stroke-width="1.5" />
                <circle cx="38" cy="38" r="4.5" fill="#60a5fa" stroke="#ffffff" stroke-width="1.5" />
                <circle cx="50" cy="18" r="6" fill="#10b981" stroke="#ffffff" stroke-width="2" />
                <circle cx="50" cy="18" r="2.5" fill="#ffffff" />
              </svg>
            </div>
          </div>

          <div class="loading-typography">
            <h3 class="destination-title">{{ destinationTitle() }}</h3>
            <div class="progress-indicator-row">
              <div class="progress-bar-mini">
                <div class="bar-fill-mini" [style.width.%]="progress()"></div>
              </div>
              <span class="percentage-label">{{ progress() }}%</span>
            </div>
            <div class="status-indicator">
              <span class="pulsing-beacon"></span>
              <span class="status-description">{{ statusMessage() }}</span>
            </div>
          </div>
        </div>
      </div>
    }
  `,
  styles: [`
    .route-loading-page {
      position: fixed;
      inset: 0;
      z-index: 999999;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background: rgba(8, 12, 20, 0.88);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      opacity: 1;
      transition: opacity 0.32s cubic-bezier(0.16, 1, 0.3, 1), transform 0.32s ease;
      pointer-events: all;
      user-select: none;
    }

    [data-theme="light"] .route-loading-page {
      background: rgba(248, 250, 252, 0.90);
    }

    .route-loading-page.fading-out {
      opacity: 0;
      transform: scale(1.02);
      pointer-events: none;
    }

    /* Ambient Background Glow */
    .ambient-glow {
      position: absolute;
      width: 450px;
      height: 450px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(99, 102, 241, 0.18) 0%, rgba(56, 189, 248, 0.12) 50%, transparent 75%);
      filter: blur(50px);
      pointer-events: none;
      animation: pulseGlow 3s ease-in-out infinite;
    }

    .cyber-grid-overlay {
      position: absolute;
      inset: 0;
      background-image: 
        linear-gradient(to right, rgba(255, 255, 255, 0.02) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(255, 255, 255, 0.02) 1px, transparent 1px);
      background-size: 36px 36px;
      pointer-events: none;
    }

    [data-theme="light"] .cyber-grid-overlay {
      background-image: 
        linear-gradient(to right, rgba(99, 102, 241, 0.04) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(99, 102, 241, 0.04) 1px, transparent 1px);
    }

    /* Top Laser Beam Track */
    .top-laser-track {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: rgba(255, 255, 255, 0.06);
      overflow: hidden;
    }

    .laser-beam {
      height: 100%;
      background: linear-gradient(90deg, #6366f1 0%, #38bdf8 50%, #10b981 100%);
      box-shadow: 0 0 16px rgba(99, 102, 241, 0.9), 0 0 28px rgba(56, 189, 248, 0.6);
      transition: width 0.18s cubic-bezier(0.1, 0.9, 0.2, 1);
      position: relative;
    }

    .laser-flare {
      position: absolute;
      right: -5px;
      top: -4px;
      bottom: -4px;
      width: 50px;
      background: radial-gradient(circle, #ffffff 10%, #38bdf8 50%, transparent 80%);
      border-radius: 50%;
      filter: blur(1px);
    }

    /* Central Box */
    .loading-stage-box {
      position: relative;
      z-index: 10;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 1.75rem;
      padding: 2.5rem 3.5rem;
      border-radius: 28px;
      background: rgba(15, 23, 42, 0.65);
      border: 1px solid rgba(255, 255, 255, 0.1);
      box-shadow: 0 24px 60px -12px rgba(0, 0, 0, 0.5), 0 0 40px rgba(99, 102, 241, 0.15);
      animation: appearStage 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    [data-theme="light"] .loading-stage-box {
      background: rgba(255, 255, 255, 0.95);
      border-color: rgba(99, 102, 241, 0.25);
      box-shadow: 0 24px 60px -12px rgba(99, 102, 241, 0.2), 0 0 30px rgba(99, 102, 241, 0.1);
    }

    /* Orb Scanner Stage */
    .orb-scanner-stage {
      position: relative;
      width: 104px;
      height: 104px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .scanner-ring {
      position: absolute;
      border-radius: 50%;
    }

    .outer-ring {
      inset: 0;
      border: 2px solid transparent;
      border-top-color: #6366f1;
      border-right-color: #38bdf8;
      animation: spinClockwise 1.1s linear infinite;
      box-shadow: 0 0 20px rgba(99, 102, 241, 0.35);
    }

    .inner-ring {
      inset: 8px;
      border: 2px solid transparent;
      border-bottom-color: #10b981;
      border-left-color: #6366f1;
      animation: spinCounterClockwise 1.4s linear infinite;
      opacity: 0.85;
    }

    .core-emblem-badge {
      width: 68px;
      height: 68px;
      background: rgba(15, 23, 42, 0.95);
      border: 1px solid rgba(255, 255, 255, 0.14);
      border-radius: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5), inset 0 0 15px rgba(99, 102, 241, 0.3);
    }

    [data-theme="light"] .core-emblem-badge {
      background: #ffffff;
      border-color: rgba(99, 102, 241, 0.25);
      box-shadow: 0 10px 30px rgba(99, 102, 241, 0.18);
    }

    .emblem-svg {
      animation: emblemBreathe 2.2s ease-in-out infinite;
    }

    /* Typography & Progress */
    .loading-typography {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 0.75rem;
      text-align: center;
    }

    .destination-title {
      font-size: 1.25rem;
      font-weight: 800;
      color: #f8fafc;
      letter-spacing: -0.015em;
    }

    [data-theme="light"] .destination-title {
      color: #0f172a;
    }

    [data-theme="light"] .status-description {
      color: #475569;
    }

    .progress-indicator-row {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      width: 220px;
    }

    .progress-bar-mini {
      flex: 1;
      height: 5px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 9999px;
      overflow: hidden;
    }

    [data-theme="light"] .progress-bar-mini {
      background: rgba(0, 0, 0, 0.08);
    }

    .bar-fill-mini {
      height: 100%;
      background: linear-gradient(90deg, #6366f1, #38bdf8);
      border-radius: 9999px;
      transition: width 0.15s ease;
    }

    .percentage-label {
      font-family: var(--font-family-mono, monospace);
      font-size: 0.8125rem;
      font-weight: 700;
      color: #38bdf8;
      width: 38px;
      text-align: right;
    }

    .status-indicator {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .pulsing-beacon {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #10b981;
      box-shadow: 0 0 10px #10b981;
      animation: pulseBeacon 1.2s ease-in-out infinite;
    }

    .status-description {
      font-size: 0.8125rem;
      font-weight: 500;
      color: var(--text-muted, #94a3b8);
      letter-spacing: 0.02em;
    }

    @keyframes spinClockwise {
      to { transform: rotate(360deg); }
    }

    @keyframes spinCounterClockwise {
      to { transform: rotate(-360deg); }
    }

    @keyframes emblemBreathe {
      0%, 100% { transform: scale(1); filter: drop-shadow(0 0 2px rgba(99, 102, 241, 0.4)); }
      50% { transform: scale(1.08); filter: drop-shadow(0 0 10px rgba(56, 189, 248, 0.7)); }
    }

    @keyframes pulseGlow {
      0%, 100% { transform: scale(1); opacity: 0.8; }
      50% { transform: scale(1.15); opacity: 1; }
    }

    @keyframes pulseBeacon {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.35; transform: scale(0.8); }
    }

    @keyframes appearStage {
      from { opacity: 0; transform: translateY(12px) scale(0.95); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }
  `]
})
export class RouteProgressBarComponent implements OnInit, OnDestroy {
  private readonly router = inject(Router);
  private sub?: Subscription;
  private timer?: any;
  private hideTimer?: any;
  private startTime = 0;

  readonly isActive = signal<boolean>(false);
  readonly isFadingOut = signal<boolean>(false);
  readonly progress = signal<number>(0);
  readonly destinationTitle = signal<string>('AdPlatform Intelligence');
  readonly statusMessage = signal<string>('Transitioning workspace...');

  ngOnInit(): void {
    this.sub = this.router.events.subscribe(event => {
      if (event instanceof NavigationStart) {
        this.startLoading(event.url);
      } else if (
        event instanceof NavigationEnd ||
        event instanceof NavigationCancel ||
        event instanceof NavigationError
      ) {
        this.completeLoading();
      }
    });
  }

  private startLoading(url: string): void {
    this.startTime = Date.now();
    this.isFadingOut.set(false);
    this.isActive.set(true);
    this.progress.set(18);

    // Context-aware destination title & status
    if (url.includes('operations-research')) {
      this.destinationTitle.set('Operations Research Optimizer');
      this.statusMessage.set('Evaluating convex diminishing returns...');
    } else if (url.includes('campaigns')) {
      this.destinationTitle.set('Multi-Channel Campaigns Hub');
      this.statusMessage.set('Synchronizing campaign telemetry...');
    } else if (url.includes('ai-center')) {
      this.destinationTitle.set('AI Intelligence & Model Hub');
      this.statusMessage.set('Connecting active model gateways...');
    } else if (url.includes('accounts')) {
      this.destinationTitle.set('Integrations & Connected Accounts');
      this.statusMessage.set('Checking platform app setup and account connections...');
    } else if (url.includes('team')) {
      this.destinationTitle.set('Team & Access Management');
      this.statusMessage.set('Loading role-based permissions...');
    } else {
      this.destinationTitle.set('Overview Intelligence Dashboard');
      this.statusMessage.set('Harmonizing cross-channel metrics...');
    }

    clearInterval(this.timer);
    clearTimeout(this.hideTimer);

    // Incremental progress simulation
    this.timer = setInterval(() => {
      if (this.progress() < 90) {
        this.progress.update(p => p + Math.floor(Math.random() * 10) + 7);
      }
    }, 70);
  }

  private completeLoading(): void {
    this.progress.set(100);
    this.statusMessage.set('Workspace ready');
    clearInterval(this.timer);

    // Provide a noticeable, ultra-smooth premium transition time (min 600ms)
    const elapsed = Date.now() - this.startTime;
    const remainingTime = Math.max(0, 620 - elapsed);

    setTimeout(() => {
      this.isFadingOut.set(true);
      this.hideTimer = setTimeout(() => {
        this.isActive.set(false);
        this.isFadingOut.set(false);
        this.progress.set(0);
      }, 300);
    }, remainingTime);
  }

  ngOnDestroy(): void {
    this.sub?.unsubscribe();
    clearInterval(this.timer);
    clearTimeout(this.hideTimer);
  }
}
