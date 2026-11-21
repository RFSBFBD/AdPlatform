import { Component, inject, OnInit } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { Title } from '@angular/platform-browser';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet],
  templateUrl: './app.html',
  styleUrl: './app.scss'
})
export class App implements OnInit {
  private readonly titleService = inject(Title);

  ngOnInit(): void {
    this.titleService.setTitle('AdPlatform — Cross-Channel Intelligence Hub');
  }
}
