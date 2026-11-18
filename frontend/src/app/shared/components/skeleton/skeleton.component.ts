import { Component, input } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-skeleton',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div 
      class="skeleton-box" 
      [style.width]="width()" 
      [style.height]="height()" 
      [style.borderRadius]="radius()"
      aria-hidden="true"
    ></div>
  `,
  styles: [`
    .skeleton-box {
      background: linear-gradient(
        90deg,
        var(--bg-subtle) 25%,
        var(--bg-hover) 50%,
        var(--bg-subtle) 75%
      );
      background-size: 200% 100%;
      animation: shimmer 1.5s infinite;
    }

    @keyframes shimmer {
      0% { background-position: 200% 0; }
      100% { background-position: -200% 0; }
    }
  `]
})
export class SkeletonComponent {
  readonly width = input<string>('100%');
  readonly height = input<string>('1rem');
  readonly radius = input<string>('var(--radius-sm)');
}
