import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-icon',
  standalone: true,
  imports: [CommonModule],
  template: `
    <span class="icon-wrapper" [style.width.px]="size" [style.height.px]="size">
      <!-- 1. Real Official Brand Icons (Authentic Logos with native Brand Colors) -->
      @if (name === 'meta') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" class="brand-svg">
          <defs>
            <linearGradient id="meta-loop-grad" x1="0%" y1="100%" x2="100%" y2="0%">
              <stop offset="0%" stop-color="#0064E0"/>
              <stop offset="50%" stop-color="#0081FB"/>
              <stop offset="100%" stop-color="#0079FF"/>
            </linearGradient>
          </defs>
          <path d="M12.0003 7.55286C8.80789 2.76679 4.90807 3.86211 2.94435 5.79524C0.603314 8.09971 0.490892 11.9054 2.68412 14.5202C4.78768 17.0279 8.23924 17.2625 10.4287 14.9392L11.9961 13.2759L13.5678 14.9434C15.7573 17.2625 19.2088 17.0279 21.3124 14.5202C23.5098 11.9054 23.3932 8.09971 21.0522 5.79524C19.0885 3.86211 15.1928 2.76679 12.0003 7.55286ZM18.7844 13.0645C17.2954 14.8373 14.872 14.8967 13.4357 13.2505L12.0003 11.7265L10.5607 13.2505C9.12439 14.8967 6.701 14.8373 5.21203 13.0645C3.76472 11.3374 3.83401 8.87898 5.37227 7.36437C6.67134 6.08479 9.38721 5.4385 11.9961 9.42629L12.0003 9.43265L12.0045 9.42629C14.6134 5.4385 17.3292 6.08479 18.6283 7.36437C20.1666 8.87898 20.2358 11.3374 18.7844 13.0645Z" fill="url(#meta-loop-grad)"/>
        </svg>
      }

      @else if (name === 'google') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" class="brand-svg">
          <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
          <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
          <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z" fill="#FBBC05"/>
          <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z" fill="#EA4335"/>
        </svg>
      }

      @else if (name === 'tiktok') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" class="brand-svg">
          <path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64c.298-.002.595.042.88.13V9.4a6.33 6.33 0 0 0-1-.08A6.34 6.34 0 0 0 3 15.66a6.34 6.34 0 0 0 10.82 4.48 6.3 6.3 0 0 0 1.95-4.57V8.75a8.16 8.16 0 0 0 4.82 1.57V6.88a4.85 4.85 0 0 1-1-.19z" fill="#FE2C55"/>
          <path d="M18.8 6.2a4.83 4.83 0 0 1-3.77-4.25V1.5h-2.5v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64c.298-.002.595.042.88.13V8.9a6.33 6.33 0 0 0-1-.08A6.34 6.34 0 0 0 2.5 15.16a6.34 6.34 0 0 0 10.82 4.48 6.3 6.3 0 0 0 1.95-4.57V8.25a8.16 8.16 0 0 0 4.82 1.57V6.39a4.85 4.85 0 0 1-1.29-.19z" fill="#25F4EE" style="mix-blend-mode: screen; opacity: 0.85"/>
        </svg>
      }

      @else if (name === 'looker') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" class="brand-svg">
          <circle cx="6" cy="6" r="3" fill="#4285F4"/>
          <circle cx="18" cy="6" r="3" fill="#EA4335"/>
          <circle cx="18" cy="18" r="3" fill="#34A853"/>
          <circle cx="6" cy="18" r="3" fill="#FBBC05"/>
          <path d="M6 9v6M9 6h6M15 18H9M18 9v6" stroke="#4285F4" stroke-width="2" stroke-linecap="round"/>
        </svg>
      }

      <!-- 2. Standard Monochrome Interface Icons -->
      @else {
        <svg 
          [attr.width]="size" 
          [attr.height]="size" 
          viewBox="0 0 24 24" 
          fill="none" 
          stroke="currentColor" 
          [attr.stroke-width]="strokeWidth" 
          stroke-linecap="round" 
          stroke-linejoin="round"
          class="svg-icon"
        >
          <!-- Dashboard / Analytics -->
          @if (name === 'dashboard') {
            <rect x="3" y="3" width="7" height="9" rx="1.5"></rect>
            <rect x="14" y="3" width="7" height="5" rx="1.5"></rect>
            <rect x="14" y="12" width="7" height="9" rx="1.5"></rect>
            <rect x="3" y="16" width="7" height="5" rx="1.5"></rect>
          }

          <!-- Operations Research / Chart -->
          @else if (name === 'chart') {
            <line x1="18" y1="20" x2="18" y2="10"></line>
            <line x1="12" y1="20" x2="12" y2="4"></line>
            <line x1="6" y1="20" x2="6" y2="14"></line>
          }

          <!-- Campaigns / Target -->
          @else if (name === 'campaigns' || name === 'target') {
            <circle cx="12" cy="12" r="10"></circle>
            <circle cx="12" cy="12" r="6"></circle>
            <circle cx="12" cy="12" r="2"></circle>
          }

          <!-- Connected Accounts / Link -->
          @else if (name === 'accounts' || name === 'link') {
            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
          }

          <!-- AI Center / Sparkles -->
          @else if (name === 'ai' || name === 'sparkles') {
            <path d="M12 3l1.912 5.885L20 10l-5.088 3.885L16.824 20 12 16.115 7.176 20l1.912-6.115L4 10l6.088-1.115L12 3z"></path>
          }

          <!-- AI Chatbot / Bot -->
          @else if (name === 'chat' || name === 'bot') {
            <path d="M12 8V4H8"></path>
            <rect width="16" height="12" x="4" y="8" rx="2"></rect>
            <path d="M2 14h2"></path>
            <path d="M20 14h2"></path>
            <path d="M15 13v2"></path>
            <path d="M9 13v2"></path>
          }

          <!-- Developer API / Terminal / Code -->
          @else if (name === 'developer' || name === 'code') {
            <polyline points="16 18 22 12 16 6"></polyline>
            <polyline points="8 6 2 12 8 18"></polyline>
            <line x1="14" y1="4" x2="10" y2="20"></line>
          }

          <!-- Team / Users -->
          @else if (name === 'team' || name === 'users') {
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
            <circle cx="9" cy="7" r="4"></circle>
            <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
          }

          <!-- Settings -->
          @else if (name === 'settings') {
            <circle cx="12" cy="12" r="3"></circle>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
          }

          <!-- Logout / Power -->
          @else if (name === 'logout') {
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
            <polyline points="16 17 21 12 16 7"></polyline>
            <line x1="21" y1="12" x2="9" y2="12"></line>
          }

          <!-- Menu Hamburger -->
          @else if (name === 'menu') {
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
          }

          <!-- Close X -->
          @else if (name === 'close' || name === 'x') {
            <line x1="18" y1="6" x2="6" y2="18"></line>
            <line x1="6" y1="6" x2="18" y2="18"></line>
          }

          <!-- Check -->
          @else if (name === 'check') {
            <polyline points="20 6 9 17 4 12"></polyline>
          }

          <!-- Alert / Warning -->
          @else if (name === 'alert') {
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
          }

          <!-- Plus -->
          @else if (name === 'plus') {
            <line x1="12" y1="5" x2="12" y2="19"></line>
            <line x1="5" y1="12" x2="19" y2="12"></line>
          }

          <!-- Sync / Refresh -->
          @else if (name === 'sync' || name === 'refresh') {
            <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"></path>
          }

          <!-- Key -->
          @else if (name === 'key') {
            <path d="m21 2-2 2m-1.5 1.5L16 7l-1.5-1.5L13 7l-1.5-1.5L10 7l-.5-.5L7 9H4a2 2 0 0 0-2 2v2a2 2 0 0 0 2 2h3l7.5-7.5"></path>
            <circle cx="18.5" cy="5.5" r="2.5"></circle>
          }

          <!-- Eye / Password Toggle -->
          @else if (name === 'eye') {
            <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"></path>
            <circle cx="12" cy="12" r="3"></circle>
          }
          @else if (name === 'eye-off') {
            <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"></path>
            <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"></path>
            <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"></path>
            <line x1="2" y1="2" x2="22" y2="22"></line>
          }

          <!-- Send -->
          @else if (name === 'send') {
            <line x1="22" y1="2" x2="11" y2="13"></line>
            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
          }

          <!-- Trend Up & Down -->
          @else if (name === 'trend-up') {
            <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
            <polyline points="17 6 23 6 23 12"></polyline>
          }
          @else if (name === 'trend-down') {
            <polyline points="23 18 13.5 8.5 8.5 13.5 1 6"></polyline>
            <polyline points="17 18 23 18 23 12"></polyline>
          }

          <!-- External Link -->
          @else if (name === 'external-link') {
            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
            <polyline points="15 3 21 3 21 9"></polyline>
            <line x1="10" y1="14" x2="21" y2="3"></line>
          }

          <!-- Copy -->
          @else if (name === 'copy') {
            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
          }

          <!-- Trash / Delete -->
          @else if (name === 'trash' || name === 'delete') {
            <polyline points="3 6 5 6 21 6"></polyline>
            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
          }

          <!-- Default circle -->
          @else {
            <circle cx="12" cy="12" r="8"></circle>
          }
        </svg>
      }
    </span>
  `,
  styles: [`
    :host {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      line-height: 0;
    }
    .icon-wrapper {
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .svg-icon {
      display: block;
      transition: stroke 0.2s ease, transform 0.2s ease;
    }
    .brand-svg {
      display: block;
      transition: transform 0.2s ease, filter 0.2s ease;
      &:hover {
        transform: scale(1.08);
      }
    }
  `]
})
export class IconComponent {
  @Input() name: string = 'dashboard';
  @Input() size: number = 18;
  @Input() strokeWidth: number = 2;
}
