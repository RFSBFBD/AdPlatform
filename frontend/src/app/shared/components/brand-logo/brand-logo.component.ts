import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-brand-logo',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="brand-emblem" [style.width.px]="size" [style.height.px]="size">
      <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <linearGradient id="brandGrad" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#6366F1"/>
            <stop offset="50%" stop-color="#8B5CF6"/>
            <stop offset="100%" stop-color="#06B6D4"/>
          </linearGradient>
          <linearGradient id="pulseGlow" x1="0%" y1="100%" x2="100%" y2="0%">
            <stop offset="0%" stop-color="#3B82F6" stop-opacity="0.8"/>
            <stop offset="100%" stop-color="#10B981" stop-opacity="0.9"/>
          </linearGradient>
          <filter id="glow" x="-20%" y="-20%" width="140%" height="140%">
            <feGaussianBlur stdDeviation="2" result="blur"/>
            <feComposite in="SourceGraphic" in2="blur" operator="over"/>
          </filter>
        </defs>

        <!-- Outer Squircle with Glass Border -->
        <rect x="2" y="2" width="36" height="36" rx="10" fill="#0F172A" stroke="url(#brandGrad)" stroke-width="2"/>
        
        <!-- Underlying Wave Grid -->
        <path d="M7 29L15 22L23 26L33 13" stroke="url(#pulseGlow)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        
        <!-- Secondary Harmonic Curve -->
        <path d="M7 32C14 31 18 20 25 18C28 17 31 19 33 16" stroke="rgba(255, 255, 255, 0.25)" stroke-width="1.5" stroke-linecap="round" stroke-dasharray="2 3"/>

        <!-- Performance Nodes with Core Glow -->
        <circle cx="15" cy="22" r="3" fill="#6366F1" stroke="#FFFFFF" stroke-width="1.5"/>
        <circle cx="23" cy="26" r="2.5" fill="#8B5CF6" stroke="#FFFFFF" stroke-width="1.5"/>
        <circle cx="33" cy="13" r="3.5" fill="#10B981" stroke="#FFFFFF" stroke-width="1.5" filter="url(#glow)"/>
        <circle cx="7" cy="29" r="2" fill="#06B6D4"/>
      </svg>
    </div>
  `,
  styles: [`
    :host {
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .brand-emblem {
      display: flex;
      align-items: center;
      justify-content: center;
      filter: drop-shadow(0 4px 12px rgba(99, 102, 241, 0.35));
      transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .brand-emblem:hover {
      transform: scale(1.08) rotate(2deg);
    }
  `]
})
export class BrandLogoComponent {
  @Input() size: number = 38;
}
