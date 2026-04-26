<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>SuffraTech</title>
  <link rel="icon" type="image/png" href="suffratech.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,700;0,800;0,900;1,700&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <style>
    :root {
      --forest: #091c13;
      --forest2: #0c2018;
      --forest3: #112b1e;
      --forest4: #163625;
      --leaf: #1e4d30;
      --gold: #c9a227;
      --gold2: #ddb83a;
      --gold-light: #f0d07a;
      --gold-glow: rgba(201, 162, 39, 0.18);
      --cream: #f5f0e8;
      --white: #ffffff;
      --text-muted: rgba(245, 240, 232, 0.58);
      --text-dim: rgba(245, 240, 232, 0.32);
      --border: rgba(201, 162, 39, 0.13);
      --border2: rgba(201, 162, 39, 0.28);
      --border3: rgba(201, 162, 39, 0.45);
      --fb-blue: #1877F2;
      --radius: 14px;
      --radius-lg: 22px;
      --ease-out: cubic-bezier(0.22, 1, 0.36, 1);
      --green-dark: #0f3d2e;
      --green-mid: #1a7a3c;
      --ink: #0b1a10;
      --muted: rgba(11, 26, 16, 0.55);
      --shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
      --shadow-hover: 0 8px 32px rgba(0, 0, 0, 0.18);
    }

    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    html {
      scroll-behavior: smooth;
    }

    body {
      font-family: 'Outfit', sans-serif;
      background: var(--forest);
      color: var(--cream);
      line-height: 1.7;
      overflow-x: hidden;
      cursor: none;
    }

    /* ── CUSTOM CURSOR ── */
    .cursor-dot {
      position: fixed;
      width: 8px;
      height: 8px;
      background: var(--gold);
      border-radius: 50%;
      pointer-events: none;
      z-index: 9999;
      transform: translate(-50%, -50%);
      transition: transform 0.08s, opacity 0.2s;
      mix-blend-mode: screen;
    }

    .cursor-ring {
      position: fixed;
      width: 36px;
      height: 36px;
      border: 1.5px solid rgba(201, 162, 39, 0.5);
      border-radius: 50%;
      pointer-events: none;
      z-index: 9998;
      transform: translate(-50%, -50%);
      transition: width 0.3s var(--ease-out), height 0.3s var(--ease-out),
        border-color 0.3s, top 0.12s var(--ease-out), left 0.12s var(--ease-out);
    }

    body.cursor-hover .cursor-ring {
      width: 56px;
      height: 56px;
      border-color: var(--gold);
    }

    /* ── BACKGROUND ── */
    .bg-canvas {
      position: fixed;
      inset: 0;
      z-index: 0;
      pointer-events: none;
    }

    .bg-orb {
      position: absolute;
      border-radius: 50%;
      filter: blur(90px);
      opacity: 0.18;
      animation: orb-float 14s ease-in-out infinite alternate;
    }

    .bg-orb:nth-child(1) {
      width: 500px;
      height: 500px;
      background: #1e4d30;
      top: -10%;
      left: -5%;
      animation-duration: 16s;
    }

    .bg-orb:nth-child(2) {
      width: 350px;
      height: 350px;
      background: #c9a227;
      top: 30%;
      right: -8%;
      opacity: 0.09;
      animation-duration: 20s;
      animation-direction: alternate-reverse;
    }

    .bg-orb:nth-child(3) {
      width: 420px;
      height: 420px;
      background: #163625;
      bottom: -5%;
      left: 20%;
      animation-duration: 18s;
      opacity: 0.2;
    }

    .bg-orb:nth-child(4) {
      width: 200px;
      height: 200px;
      background: #c9a227;
      top: 60%;
      left: 50%;
      opacity: 0.07;
      animation-duration: 12s;
    }

    @keyframes orb-float {
      from {
        transform: translate(0, 0) scale(1);
      }

      to {
        transform: translate(30px, 20px) scale(1.08);
      }
    }

    body::after {
      content: '';
      position: fixed;
      inset: 0;
      background-image:
        linear-gradient(rgba(201, 162, 39, 0.025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(201, 162, 39, 0.025) 1px, transparent 1px);
      background-size: 60px 60px;
      pointer-events: none;
      z-index: 0;
    }

    /* ── PROGRESS BAR ── */
    #progress-bar {
      position: fixed;
      top: 0;
      left: 0;
      height: 2.5px;
      z-index: 200;
      background: linear-gradient(90deg, var(--gold), var(--gold2), var(--gold-light));
      width: 0%;
      transition: width 0.1s linear;
      box-shadow: 0 0 10px var(--gold);
    }

    /* ── NAV ── */
    nav {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      z-index: 100;
      padding: 0 5%;
      height: 66px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: transparent;
      border-bottom: 1px solid transparent;
      transition: background 0.5s, border-color 0.5s, backdrop-filter 0.5s;
    }

    nav.scrolled {
      background: rgba(9, 28, 19, 0.88);
      backdrop-filter: blur(24px) saturate(1.5);
      border-color: var(--border);
    }

    .nav-logo {
      display: flex;
      align-items: center;
      gap: 10px;
      text-decoration: none;
      transition: opacity 0.2s;
    }

    .nav-logo:hover {
      opacity: 0.85;
    }

    .logo-icon {
      width: 34px;
      height: 34px;
      border-radius: 8px;
      background: linear-gradient(135deg, var(--gold), var(--gold2));
      display: flex;
      align-items: center;
      justify-content: center;
      transition: transform 0.3s var(--ease-out), box-shadow 0.3s;
    }

    .logo-icon:hover {
      transform: rotate(-8deg) scale(1.1);
      box-shadow: 0 6px 20px rgba(201, 162, 39, 0.4);
    }

    .logo-icon svg {
      width: 18px;
      height: 18px;
      fill: var(--forest);
    }

    .logo-text {
      font-weight: 700;
      font-size: 1.05rem;
      color: var(--cream);
      letter-spacing: -0.01em;
    }

    .logo-text span {
      color: var(--gold);
    }

    .nav-links {
      display: flex;
      align-items: center;
      gap: 2px;
      list-style: none;
    }

    .nav-links a {
      padding: 7px 14px;
      color: var(--text-muted);
      text-decoration: none;
      font-size: 0.88rem;
      font-weight: 500;
      border-radius: 8px;
      transition: color 0.2s, background 0.2s;
      position: relative;
    }

    .nav-links a::after {
      content: '';
      position: absolute;
      bottom: 4px;
      left: 50%;
      right: 50%;
      height: 1.5px;
      background: var(--gold);
      border-radius: 2px;
      transition: left 0.25s var(--ease-out), right 0.25s var(--ease-out);
    }

    .nav-links a:hover {
      color: var(--cream);
      background: rgba(201, 162, 39, 0.08);
    }

    .nav-links a:hover::after {
      left: 14px;
      right: 14px;
    }

    .btn-vote-nav {
      padding: 8px 20px;
      background: var(--gold);
      color: var(--forest);
      border: none;
      border-radius: 8px;
      font-family: 'Outfit', sans-serif;
      font-weight: 700;
      font-size: 0.88rem;
      cursor: pointer;
      transition: all 0.25s var(--ease-out);
      position: relative;
      overflow: hidden;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
    }

    .btn-vote-nav::before {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      transform: translateX(-100%);
      transition: transform 0.4s;
    }

    .btn-vote-nav:hover {
      background: var(--gold2);
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(201, 162, 39, 0.35);
    }

    .btn-vote-nav:hover::before {
      transform: translateX(100%);
    }

    .hamburger {
      display: none;
      flex-direction: column;
      gap: 5px;
      cursor: pointer;
      background: none;
      border: none;
      padding: 8px;
    }

    .hamburger span {
      display: block;
      width: 22px;
      height: 2px;
      background: var(--text-muted);
      border-radius: 2px;
      transition: all 0.3s;
    }

    .mobile-nav-drawer {
      display: none;
      flex-direction: column;
      position: fixed;
      top: 66px;
      left: 0;
      right: 0;
      background: rgba(9, 28, 19, 0.97);
      backdrop-filter: blur(24px);
      padding: 20px 7%;
      border-bottom: 1px solid rgba(201, 162, 39, 0.15);
      z-index: 99;
      gap: 4px;
    }

    .mobile-nav-drawer.open {
      display: flex;
    }

    .mobile-nav-drawer a {
      padding: 10px 0;
      color: var(--text-muted);
      text-decoration: none;
      font-size: 1rem;
      font-weight: 500;
      border-bottom: 1px solid var(--border);
      transition: color 0.2s;
    }

    .mobile-nav-drawer a:hover {
      color: var(--gold);
    }

    /* ── HERO ── */
    #home {
      min-height: 100vh;
      display: grid;
      grid-template-columns: 1fr 1fr;
      align-items: center;
      padding: 100px 7% 80px;
      position: relative;
      z-index: 1;
      gap: 60px;
    }

    .hero-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(201, 162, 39, 0.1);
      border: 1px solid var(--border2);
      border-radius: 100px;
      padding: 6px 16px;
      font-size: 0.78rem;
      color: var(--gold);
      font-weight: 600;
      margin-bottom: 28px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      animation: badge-in 0.8s var(--ease-out) both;
    }

    @keyframes badge-in {
      from {
        opacity: 0;
        transform: translateY(-12px);
      }

      to {
        opacity: 1;
        transform: none;
      }
    }

    .hero-badge::before {
      content: '●';
      font-size: 0.55rem;
      animation: blink 1.8s infinite;
    }

    @keyframes blink {

      0%,
      100% {
        opacity: 1
      }

      50% {
        opacity: 0.2
      }
    }

    .hero-title {
      font-family: 'Playfair Display', serif;
      font-size: clamp(2.4rem, 5vw, 4rem);
      font-weight: 900;
      line-height: 1.08;
      letter-spacing: -0.02em;
      margin-bottom: 24px;
      animation: title-in 1s var(--ease-out) 0.15s both;
    }

    @keyframes title-in {
      from {
        opacity: 0;
        transform: translateY(20px);
      }

      to {
        opacity: 1;
        transform: none;
      }
    }

    .hero-title .accent {
      color: var(--gold);
      font-style: italic;
    }

    .typewriter::after {
      content: '|';
      color: var(--gold);
      animation: cursor-blink 0.9s step-end infinite;
    }

    @keyframes cursor-blink {

      0%,
      100% {
        opacity: 1
      }

      50% {
        opacity: 0
      }
    }

    .hero-sub {
      font-size: 1rem;
      color: var(--text-muted);
      max-width: 480px;
      margin-bottom: 36px;
      line-height: 1.85;
      animation: sub-in 1s var(--ease-out) 0.35s both;
    }

    @keyframes sub-in {
      from {
        opacity: 0;
        transform: translateY(16px);
      }

      to {
        opacity: 1;
        transform: none;
      }
    }

    .hero-actions {
      display: flex;
      gap: 14px;
      flex-wrap: wrap;
      animation: actions-in 1s var(--ease-out) 0.5s both;
    }

    @keyframes actions-in {
      from {
        opacity: 0;
        transform: translateY(12px);
      }

      to {
        opacity: 1;
        transform: none;
      }
    }

    .btn-primary-hero {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 14px 30px;
      background: var(--gold);
      color: var(--forest);
      border: none;
      border-radius: 50px;
      font-family: 'Outfit', sans-serif;
      font-weight: 700;
      font-size: 0.95rem;
      cursor: pointer;
      transition: all 0.3s var(--ease-out);
      letter-spacing: 0.04em;
      text-transform: uppercase;
      text-decoration: none;
      position: relative;
      overflow: hidden;
    }

    .btn-primary-hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background: radial-gradient(circle at center, rgba(255, 255, 255, 0.25), transparent 70%);
      opacity: 0;
      transition: opacity 0.3s;
    }

    .btn-primary-hero:hover {
      background: var(--gold2);
      transform: translateY(-3px);
      box-shadow: 0 12px 36px rgba(201, 162, 39, 0.4);
    }

    .btn-primary-hero:hover::before {
      opacity: 1;
    }

    .btn-secondary-hero {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 14px 28px;
      background: transparent;
      color: var(--cream);
      border: 1px solid rgba(245, 240, 232, 0.2);
      border-radius: 50px;
      font-family: 'Outfit', sans-serif;
      font-weight: 500;
      font-size: 0.95rem;
      cursor: pointer;
      transition: all 0.3s var(--ease-out);
      text-decoration: none;
    }

    .btn-secondary-hero:hover {
      border-color: var(--gold);
      color: var(--gold);
      transform: translateY(-2px);
    }

    .hero-right {
      position: relative;
      animation: card-in 1.1s var(--ease-out) 0.2s both;
    }

    @keyframes card-in {
      from {
        opacity: 0;
        transform: translateY(30px) scale(0.97);
      }

      to {
        opacity: 1;
        transform: none;
      }
    }

    .clas-card {
      background: linear-gradient(145deg, rgba(22, 54, 36, 0.85), rgba(12, 32, 24, 0.9));
      border: 1px solid var(--border2);
      border-radius: var(--radius-lg);
      padding: 40px 36px;
      position: relative;
      overflow: hidden;
      backdrop-filter: blur(10px);
      transition: transform 0.4s var(--ease-out), box-shadow 0.4s;
    }

    .clas-card:hover {
      transform: translateY(-6px) scale(1.01);
      box-shadow: 0 30px 80px rgba(0, 0, 0, 0.4), 0 0 0 1px var(--border2);
    }

    .clas-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--gold), var(--gold2), var(--gold-light), var(--gold));
      background-size: 200%;
      animation: shimmer-bar 3s linear infinite;
    }

    @keyframes shimmer-bar {
      from {
        background-position: 0%
      }

      to {
        background-position: 200%
      }
    }

    .card-particles {
      position: absolute;
      inset: 0;
      pointer-events: none;
      overflow: hidden;
    }

    .card-particle {
      position: absolute;
      width: 3px;
      height: 3px;
      background: var(--gold);
      border-radius: 50%;
      opacity: 0;
      animation: particle-float linear infinite;
    }

    @keyframes particle-float {
      0% {
        opacity: 0;
        transform: translateY(0) scale(0);
      }

      20% {
        opacity: 0.6;
        transform: translateY(-20px) scale(1);
      }

      80% {
        opacity: 0.3;
      }

      100% {
        opacity: 0;
        transform: translateY(-80px) scale(0.5);
      }
    }

    .clas-card-header {
      display: flex;
      align-items: center;
      gap: 20px;
      margin-bottom: 24px;
      position: relative;
      z-index: 1;
    }

    .clas-seal {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      background: linear-gradient(135deg, #e8c97a, #c9a227);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 2rem;
      flex-shrink: 0;
      box-shadow: 0 0 0 3px rgba(201, 162, 39, 0.2), 0 0 20px rgba(201, 162, 39, 0.15);
      animation: seal-pulse 3s ease-in-out infinite;
      overflow: hidden;
    }

    @keyframes seal-pulse {

      0%,
      100% {
        box-shadow: 0 0 0 3px rgba(201, 162, 39, 0.2), 0 0 20px rgba(201, 162, 39, 0.15);
      }

      50% {
        box-shadow: 0 0 0 5px rgba(201, 162, 39, 0.3), 0 0 35px rgba(201, 162, 39, 0.25);
      }
    }

    .clas-seal img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 50%;
    }

    .active-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(52, 211, 120, 0.12);
      border: 1px solid rgba(52, 211, 120, 0.28);
      border-radius: 100px;
      padding: 3px 10px;
      font-size: 0.72rem;
      color: #52d47a;
      font-weight: 600;
      margin-bottom: 6px;
      letter-spacing: 0.06em;
      text-transform: uppercase;
    }

    .active-badge::before {
      content: '●';
      font-size: 0.5rem;
      animation: blink 1.4s infinite;
    }

    .clas-name {
      font-family: 'Playfair Display', serif;
      font-size: 1.5rem;
      font-weight: 800;
      line-height: 1.1;
    }

    .clas-name span {
      color: var(--gold);
      display: block;
    }

    .clas-school {
      font-size: 0.72rem;
      color: var(--text-dim);
      letter-spacing: 0.1em;
      text-transform: uppercase;
      margin-top: 4px;
    }

    .clas-desc {
      color: var(--text-muted);
      font-size: 0.9rem;
      line-height: 1.8;
      margin-bottom: 24px;
      position: relative;
      z-index: 1;
    }

    .clas-btn {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 13px 28px;
      background: var(--gold);
      color: var(--forest);
      border: none;
      border-radius: 50px;
      font-family: 'Outfit', sans-serif;
      font-weight: 700;
      font-size: 0.9rem;
      cursor: pointer;
      transition: all 0.25s var(--ease-out);
      letter-spacing: 0.04em;
      text-transform: uppercase;
      text-decoration: none;
      position: relative;
      z-index: 1;
      overflow: hidden;
    }

    .clas-btn:hover {
      background: var(--gold2);
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(201, 162, 39, 0.35);
    }

    .clas-btn svg {
      width: 16px;
      height: 16px;
      fill: var(--forest);
    }

    /* ── SECTION SHARED ── */
    section {
      padding: 90px 7%;
      position: relative;
      z-index: 1;
    }

    .section-tag {
      font-size: 0.73rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      color: var(--gold);
      margin-bottom: 10px;
      display: inline-block;
    }

    .section-title {
      font-family: 'Playfair Display', serif;
      font-size: clamp(1.8rem, 3.5vw, 2.6rem);
      font-weight: 800;
      letter-spacing: -0.02em;
      line-height: 1.15;
      margin-bottom: 14px;
    }

    .section-sub {
      color: var(--text-muted);
      font-size: 1rem;
      line-height: 1.85;
      max-width: 540px;
    }

    .text-center {
      text-align: center;
    }

    .mx-auto {
      margin-left: auto;
      margin-right: auto;
    }

    /* ── FACEBOOK PAGES ── */
    #facebook {
      background: var(--forest2);
      border-top: 1px solid var(--border);
    }

    .fb-header {
      text-align: center;
      margin-bottom: 52px;
    }

    .fb-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 20px;
      max-width: 1100px;
      margin: 0 auto;
    }

    .fb-card {
      background: var(--forest3);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 28px 24px;
      display: flex;
      flex-direction: column;
      transition: border-color 0.3s, transform 0.35s var(--ease-out), box-shadow 0.35s;
      position: relative;
      overflow: hidden;
      text-decoration: none;
      color: inherit;
    }

    .fb-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 2px;
      background: linear-gradient(90deg, var(--fb-blue), #4fa3f7);
      transform: scaleX(0);
      transform-origin: left;
      transition: transform 0.4s var(--ease-out);
    }

    .fb-card::after {
      content: '';
      position: absolute;
      inset: 0;
      background: radial-gradient(ellipse at var(--mx, 50%) var(--my, 50%), rgba(24, 119, 242, 0.07), transparent 60%);
      opacity: 0;
      transition: opacity 0.3s;
      pointer-events: none;
    }

    .fb-card:hover {
      border-color: rgba(24, 119, 242, 0.3);
      transform: translateY(-5px);
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
    }

    .fb-card:hover::before {
      transform: scaleX(1);
    }

    .fb-card:hover::after {
      opacity: 1;
    }

    .fb-card-top {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 14px;
    }

    .fb-avatar {
      width: 52px;
      height: 52px;
      border-radius: 12px;
      background: linear-gradient(135deg, #1877F2, #4fa3f7);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      font-size: 1.4rem;
      font-weight: 900;
      color: white;
      transition: transform 0.3s var(--ease-out), box-shadow 0.3s;
    }

    .fb-card:hover .fb-avatar {
      transform: scale(1.08) rotate(-4deg);
      box-shadow: 0 8px 20px rgba(24, 119, 242, 0.3);
    }

    .fb-page-name {
      font-weight: 700;
      font-size: 1rem;
      margin-bottom: 2px;
    }

    .fb-handle {
      font-size: 0.78rem;
      color: var(--text-dim);
    }

    .fb-desc {
      color: var(--text-muted);
      font-size: 0.88rem;
      line-height: 1.75;
      flex: 1;
      margin-bottom: 18px;
    }

    .fb-type-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-size: 0.72rem;
      color: var(--text-dim);
      text-transform: uppercase;
      letter-spacing: 0.07em;
      margin-bottom: 14px;
    }

    .fb-follow-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 10px 0;
      width: 100%;
      background: rgba(24, 119, 242, 0.1);
      border: 1px solid rgba(24, 119, 242, 0.22);
      border-radius: 8px;
      color: #4fa3f7;
      font-family: 'Outfit', sans-serif;
      font-weight: 600;
      font-size: 0.85rem;
      cursor: pointer;
      transition: all 0.25s var(--ease-out);
      text-decoration: none;
    }

    .fb-follow-btn:hover {
      background: rgba(24, 119, 242, 0.2);
      border-color: rgba(24, 119, 242, 0.45);
      transform: translateY(-1px);
    }

    .fb-follow-btn svg {
      width: 16px;
      height: 16px;
      fill: #4fa3f7;
      flex-shrink: 0;
    }

    /* ── WHY VOTE ── */
    #whyvote {
      background: var(--forest);
      border-top: 1px solid var(--border);
    }

    .why-inner {
      max-width: 1100px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 80px;
      align-items: center;
    }

    .why-right {
      display: flex;
      flex-direction: column;
      gap: 18px;
    }

    .why-item {
      background: var(--forest3);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 22px 24px;
      display: flex;
      gap: 16px;
      align-items: flex-start;
      transition: border-color 0.3s, transform 0.3s var(--ease-out), box-shadow 0.3s;
      position: relative;
      overflow: hidden;
    }

    .why-item::before {
      content: '';
      position: absolute;
      left: 0;
      top: 0;
      bottom: 0;
      width: 3px;
      background: linear-gradient(180deg, var(--gold), var(--gold2));
      transform: scaleY(0);
      transform-origin: top;
      transition: transform 0.35s var(--ease-out);
    }

    .why-item:hover {
      border-color: var(--border2);
      transform: translateX(6px);
      box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
    }

    .why-item:hover::before {
      transform: scaleY(1);
    }

    .why-icon {
      width: 44px;
      height: 44px;
      border-radius: 10px;
      background: rgba(201, 162, 39, 0.1);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      flex-shrink: 0;
      transition: transform 0.3s var(--ease-out), background 0.3s;
    }

    .why-item:hover .why-icon {
      transform: scale(1.12) rotate(-5deg);
      background: rgba(201, 162, 39, 0.18);
    }

    .why-item-title {
      font-weight: 700;
      margin-bottom: 4px;
      font-size: 1rem;
    }

    .why-item-desc {
      color: var(--text-muted);
      font-size: 0.88rem;
      line-height: 1.75;
    }

    .quote-block {
      background: var(--forest3);
      border-left: 3px solid var(--gold);
      border-radius: 0 var(--radius) var(--radius) 0;
      padding: 22px 24px;
      margin-top: 28px;
      font-size: 0.95rem;
      color: var(--text-muted);
      font-style: italic;
      line-height: 1.8;
      position: relative;
    }

    .quote-block::before {
      content: '"';
      position: absolute;
      top: -10px;
      left: 20px;
      font-size: 4rem;
      color: var(--gold);
      opacity: 0.2;
      font-family: 'Playfair Display', serif;
      line-height: 1;
    }

    /* ── DEVELOPERS SECTION ── */
    #developers {
      background: var(--forest2);
      border-top: 1px solid var(--border);
    }

    .dev-section-inner {
      max-width: 900px;
      margin: 0 auto;
    }

    .dev-section-header {
      text-align: center;
      margin-bottom: 48px;
    }

    .dev-cards-grid {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .dev-tab-card {
      background: rgba(245, 240, 232, 0.04);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 26px 30px;
      display: flex;
      align-items: flex-start;
      gap: 22px;
      transition: box-shadow 0.3s, transform 0.3s, border-color 0.3s, background 0.3s;
      position: relative;
      overflow: hidden;
    }

    .dev-tab-card::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      width: 4px;
      height: 100%;
      background: linear-gradient(180deg, var(--green-mid), var(--gold));
    }

    .dev-tab-card:hover {
      background: rgba(245, 240, 232, 0.07);
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.25);
      transform: translateY(-3px);
      border-color: var(--border2);
    }

    .dev-tab-avatar {
      width: 68px;
      height: 68px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--green-dark), var(--green-mid));
      color: var(--cream);
      font-family: 'Playfair Display', serif;
      font-size: 1.15rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.3);
    }

    .dev-tab-info {
      flex: 1;
    }

    .dev-tab-name {
      font-family: 'Playfair Display', serif;
      font-size: 1.15rem;
      font-weight: 900;
      color: var(--cream);
      margin-bottom: 3px;
    }

    .dev-tab-role {
      font-size: 0.68rem;
      font-weight: 700;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: var(--gold);
      margin-bottom: 9px;
    }

    .dev-tab-bio {
      font-size: 0.88rem;
      line-height: 1.7;
      color: var(--text-muted);
      max-width: 580px;
      margin-bottom: 12px;
    }

    .dev-tab-socials {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .dev-tab-social {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 14px;
      background: rgba(201, 162, 39, 0.08);
      border: 1px solid var(--border2);
      border-radius: 8px;
      color: var(--gold);
      font-size: 0.8rem;
      font-weight: 600;
      text-decoration: none;
      transition: background 0.2s, border-color 0.2s, transform 0.2s;
    }

    .dev-tab-social:hover {
      background: rgba(201, 162, 39, 0.18);
      border-color: var(--border3);
      transform: translateY(-2px);
      color: var(--gold2);
    }

    /* ── POWERED BY ── */
    #powered {
      background: var(--forest2);
      border-top: 1px solid var(--border);
      border-bottom: 1px solid var(--border);
      padding: 70px 7%;
    }

    .powered-inner {
      max-width: 700px;
      margin: 0 auto;
      text-align: center;
    }

    .powered-logo {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
      margin-bottom: 16px;
    }

    .powered-logo-icon {
      width: 44px;
      height: 44px;
      border-radius: 10px;
      background: linear-gradient(135deg, var(--gold), var(--gold2));
      display: flex;
      align-items: center;
      justify-content: center;
      transition: transform 0.3s var(--ease-out);
    }

    .powered-logo-icon:hover {
      transform: rotate(-10deg) scale(1.1);
    }

    .powered-logo-icon svg {
      width: 24px;
      height: 24px;
      fill: var(--forest);
    }

    .powered-logo-name {
      font-weight: 800;
      font-size: 1.4rem;
      color: var(--cream);
    }

    .powered-logo-name span {
      color: var(--gold);
    }

    .powered-sub {
      color: var(--text-muted);
      font-size: 0.92rem;
      margin-bottom: 24px;
      line-height: 1.8;
    }

    .powered-tags {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      justify-content: center;
    }

    .powered-tag {
      background: rgba(201, 162, 39, 0.08);
      border: 1px solid var(--border2);
      border-radius: 100px;
      padding: 6px 14px;
      font-size: 0.78rem;
      color: var(--gold);
      font-weight: 500;
      transition: all 0.25s var(--ease-out);
      cursor: default;
    }

    .powered-tag:hover {
      background: rgba(201, 162, 39, 0.18);
      transform: translateY(-2px);
      border-color: var(--border3);
    }

    .particles-canvas {
      position: fixed;
      inset: 0;
      z-index: 0;
      pointer-events: none;
    }

    /* ── FOOTER ── */
    footer {
      background: var(--forest);
      border-top: 1px solid var(--border);
      padding: 48px 7% 28px;
      position: relative;
      z-index: 1;
    }

    .footer-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 20px;
      padding-bottom: 28px;
      border-bottom: 1px solid var(--border);
      margin-bottom: 24px;
    }

    .footer-links {
      display: flex;
      gap: 24px;
      flex-wrap: wrap;
    }

    .footer-links a {
      color: var(--text-dim);
      font-size: 0.88rem;
      text-decoration: none;
      transition: color 0.2s;
    }

    .footer-links a:hover {
      color: var(--gold);
    }

    .footer-bottom {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 12px;
    }

    .footer-bottom p {
      color: var(--text-dim);
      font-size: 0.82rem;
    }

    /* ── REVEAL ── */
    .reveal {
      opacity: 0;
      transform: translateY(30px);
      transition: opacity 0.7s var(--ease-out), transform 0.7s var(--ease-out);
    }

    .reveal.visible {
      opacity: 1;
      transform: none;
    }

    /* ── RIPPLE ── */
    .ripple-btn {
      position: relative;
      overflow: hidden;
    }

    .ripple {
      position: absolute;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.25);
      transform: scale(0);
      animation: ripple-anim 0.5s linear;
      pointer-events: none;
    }

    @keyframes ripple-anim {
      to {
        transform: scale(4);
        opacity: 0;
      }
    }

    .tilt {
      transform-style: preserve-3d;
    }

    /* ── RESPONSIVE ── */
    @media (max-width: 900px) {
      #home {
        grid-template-columns: 1fr;
        padding-top: 120px;
      }

      .hero-title {
        font-size: 2.6rem;
      }

      .why-inner {
        grid-template-columns: 1fr;
        gap: 40px;
      }

      .fb-grid {
        grid-template-columns: 1fr 1fr;
      }

      .dev-tab-card {
        flex-direction: column;
      }
    }

    @media (max-width: 600px) {
      .nav-links {
        display: none !important;
      }

      .hamburger {
        display: flex;
      }

      .fb-grid {
        grid-template-columns: 1fr;
      }

      body {
        cursor: auto;
      }

      .cursor-dot,
      .cursor-ring {
        display: none;
      }
    }

    #scroll-top {
      position: fixed;
      bottom: 28px;
      right: 28px;
      z-index: 90;
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: rgba(201, 162, 39, 0.15);
      border: 1px solid var(--border2);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.3s, transform 0.3s var(--ease-out), background 0.3s;
    }

    #scroll-top.show {
      opacity: 1;
      pointer-events: all;
    }

    #scroll-top:hover {
      background: var(--gold);
      transform: translateY(-4px);
    }

    #scroll-top:hover svg {
      fill: var(--forest);
    }

    #scroll-top svg {
      width: 18px;
      height: 18px;
      fill: var(--gold);
      transition: fill 0.2s;
    }
  </style>
</head>

<body>

  <!-- CURSOR -->
  <div class="cursor-dot" id="cursorDot"></div>
  <div class="cursor-ring" id="cursorRing"></div>

  <!-- PROGRESS BAR -->
  <div id="progress-bar"></div>

  <!-- SCROLL TO TOP -->
  <button id="scroll-top" aria-label="Scroll to top">
    <svg viewBox="0 0 24 24">
      <path d="M7.41 15.41L12 10.83l4.59 4.58L18 14l-6-6-6 6z" />
    </svg>
  </button>

  <!-- BACKGROUND -->
  <div class="bg-canvas">
    <div class="bg-orb"></div>
    <div class="bg-orb"></div>
    <div class="bg-orb"></div>
    <div class="bg-orb"></div>
  </div>
  <canvas class="particles-canvas" id="particleCanvas"></canvas>

  <!-- NAV -->
  <nav id="navbar">
    <a href="#home" class="nav-logo">
      <div class="logo-icon" style="background:transparent;box-shadow:none;">
        <img src="suffratech.png" alt="SuffraTech" style="width:34px;height:34px;object-fit:contain;mix-blend-mode:screen;" />
      </div>
      <span class="logo-text">Suffra<span>Tech</span></span>
    </a>
    <ul class="nav-links">
      <li><a href="#home">Home</a></li>
      <li><a href="#whyvote">Why Vote</a></li>
      <li><a href="#facebook">CLAS Pages</a></li>
      <li><a href="#developers">Developers</a></li>
      <li><a href="#powered">About</a></li>
    </ul>
    <div style="display:flex;align-items:center;gap:10px;">
      <a href="login.php" class="btn-vote-nav ripple-btn">Login</a>
      <button class="hamburger" id="menuBtn" aria-label="Toggle menu"><span></span><span></span><span></span></button>
    </div>
  </nav>

  <!-- MOBILE NAV DRAWER -->
  <div class="mobile-nav-drawer" id="mobileDrawer">
    <a href="#home" onclick="closeDrawer()">Home</a>
    <a href="#whyvote" onclick="closeDrawer()">Why Vote</a>
    <a href="#facebook" onclick="closeDrawer()">CLAS Pages</a>
    <a href="#developers" onclick="closeDrawer()">Developers</a>
    <a href="#powered" onclick="closeDrawer()">About</a>
    <a href="login.php" style="color:var(--gold);font-weight:700;" onclick="closeDrawer()">Login</a>
  </div>

  <!-- HERO -->
  <section id="home">
    <div class="hero-left">
      <div class="hero-badge">Secure Student Voting</div>
      <h1 class="hero-title">
        One vote can<br />
        <span class="accent typewriter" id="typewriter"></span>
      </h1>
      <p class="hero-sub">
        Before you vote, take a moment to think beyond popularity and promises. A leader should have integrity, proven actions, and a genuine heart to serve — not just good speeches. Choose with wisdom, not pressure.
      </p>
      <div class="hero-actions">
        <a href="login.php" class="btn-primary-hero ripple-btn">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" />
          </svg>
          Vote Now
        </a>
        <a href="#facebook" class="btn-secondary-hero">View CLAS Council →</a>
      </div>
    </div>

    <div class="hero-right">
      <div class="clas-card tilt" id="heroCard">
        <div class="card-particles" id="cardParticles"></div>
        <div class="clas-card-header">
          <div class="clas-seal">
            <img src="634190545_122299517906027580_7650222544121901089_n.jpg" alt="CLAS" onerror="this.style.display='none';this.parentNode.innerHTML='🏛️'" />
          </div>
          <div class="clas-info">
            <div class="active-badge">Active Election</div>
            <div class="clas-name">College of Liberal Arts &<span>Sciences Council</span></div>
            <div class="clas-school">University of Caloocan City · South Campus</div>
          </div>
        </div>
        <p class="clas-desc">
          The CLAS Council represents every student in the College of Liberal Arts and Sciences. Your vote determines who will lead, advocate, and build programs that directly shape your academic journey. Make your voice heard — it matters.
        </p>
        <a href="login.php" class="clas-btn ripple-btn">
          <svg viewBox="0 0 24 24">
            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" />
          </svg>
          Vote Now
        </a>
      </div>
    </div>
  </section>

  <!-- FACEBOOK PAGES -->
  <section id="facebook">
    <div class="fb-header reveal">
      <div class="section-tag">Connect with Us</div>
      <h2 class="section-title">CLAS Official Facebook Pages</h2>
      <p class="section-sub mx-auto">Stay updated with the latest CLAS announcements, election results, and council activities through our official social pages.</p>
    </div>
    <div class="fb-grid">
      <a href="https://www.facebook.com/CLASCouncilUCC" target="_blank" class="fb-card reveal">
        <div class="fb-card-top">
          <div class="fb-avatar" style="font-size:1rem;">CLAS</div>
          <div>
            <div class="fb-page-name">CLAS Council — UCC</div>
            <div class="fb-handle">@CLASCouncilUCC</div>
          </div>
        </div>
        <div class="fb-type-badge">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z" />
          </svg>
          Official Student Government Page
        </div>
        <p class="fb-desc">The official Facebook page of the CLAS Student Council at UCC. Get real-time updates on election schedules, council decisions, and student programs.</p>
        <div class="fb-follow-btn">
          <svg viewBox="0 0 24 24">
            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
          </svg>
          Follow Page
        </div>
      </a>
      <a href="https://www.facebook.com/clascouncilsouth" target="_blank" class="fb-card reveal" style="transition-delay:0.08s">
        <div class="fb-card-top">
          <div class="fb-avatar" style="background:linear-gradient(135deg,#1a6b32,#2e9e55);font-size:0.9rem;">CLAS-S</div>
          <div>
            <div class="fb-page-name">CLAS South Campus</div>
            <div class="fb-handle">@CLASSouthUCC</div>
          </div>
        </div>
        <div class="fb-type-badge">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
          </svg>
          South Campus Community
        </div>
        <p class="fb-desc">Community page for all CLAS students at UCC South Campus. College events, academic updates, club activities, and everything happening in your campus community.</p>
        <div class="fb-follow-btn">
          <svg viewBox="0 0 24 24">
            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
          </svg>
          Follow Page
        </div>
      </a>
      <a href="https://www.facebook.com/univofcaloocanofficial" target="_blank" class="fb-card reveal" style="transition-delay:0.16s">
        <div class="fb-card-top">
          <div class="fb-avatar" style="background:linear-gradient(135deg,#7b2d8b,#a94cc4);font-size:0.9rem;">UCC</div>
          <div>
            <div class="fb-page-name">University of Caloocan City</div>
            <div class="fb-handle">@UCCaloocan</div>
          </div>
        </div>
        <div class="fb-type-badge">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 3L1 9l11 6 9-4.91V17h2V9L12 3zm0 12.5L5 12.02V16l7 4 7-4v-3.98L12 15.5z" />
          </svg>
          University Official Page
        </div>
        <p class="fb-desc">The official Facebook page of the University of Caloocan City. Institutional news, university-wide events, admissions info, and official UCC announcements.</p>
        <div class="fb-follow-btn">
          <svg viewBox="0 0 24 24">
            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
          </svg>
          Follow Page
        </div>
      </a>
      <a href="https://www.facebook.com/CLASElectionCommitteeUCC" target="_blank" class="fb-card reveal" style="transition-delay:0.22s">
        <div class="fb-card-top">
          <div class="fb-avatar" style="background:linear-gradient(135deg,#c9a227,#ddb83a);color:#1e3a1a;font-size:0.8rem;font-weight:900;">COMELEC</div>
          <div>
            <div class="fb-page-name">CLAS COMELEC</div>
            <div class="fb-handle">@CLASElectionUCC</div>
          </div>
        </div>
        <div class="fb-type-badge">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" />
          </svg>
          Election Commission
        </div>
        <p class="fb-desc">Official page of the CLAS Commission on Elections. Follow for election rules, candidate filings, voting schedules, tally results, and all election-related announcements.</p>
        <div class="fb-follow-btn">
          <svg viewBox="0 0 24 24">
            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
          </svg>
          Follow Page
        </div>
      </a>
      <a href="https://www.facebook.com/groups/CLASStudentsUCC" target="_blank" class="fb-card reveal" style="transition-delay:0.28s">
        <div class="fb-card-top">
          <div class="fb-avatar" style="background:linear-gradient(135deg,#0a6e8a,#1a9bbf);font-size:0.85rem;">GROUP</div>
          <div>
            <div class="fb-page-name">CLAS Students Group</div>
            <div class="fb-handle">CLAS UCC Students Community</div>
          </div>
        </div>
        <div class="fb-type-badge">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
            <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z" />
          </svg>
          Student Community Group
        </div>
        <p class="fb-desc">The open Facebook group for all CLAS students. Share notes, ask questions, post opportunities, and connect with your fellow Liberal Arts and Sciences students at UCC.</p>
        <div class="fb-follow-btn">
          <svg viewBox="0 0 24 24">
            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
          </svg>
          Join Group
        </div>
      </a>
      <a href="https://www.facebook.com/SuffraElects" target="_blank" class="fb-card reveal" style="transition-delay:0.34s">
        <div class="fb-card-top">
          <div class="fb-avatar" style="background:linear-gradient(135deg,#c9a227,#ddb83a);">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="#1e3a1a">
              <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z" />
            </svg>
          </div>
          <div>
            <div class="fb-page-name">Suffra Tech Philippines</div>
            <div class="fb-handle">@SuffraElects</div>
          </div>
        </div>
        <div class="fb-type-badge">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z" />
          </svg>
          Platform Official Page
        </div>
        <p class="fb-desc">The official Facebook presence of Suffra Tech — secure, transparent, and scalable democratic infrastructure built for institutions of all sizes.</p>
        <div class="fb-follow-btn">
          <svg viewBox="0 0 24 24">
            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
          </svg>
          Follow Page
        </div>
      </a>
    </div>
  </section>

  <!-- WHY VOTE -->
  <section id="whyvote">
    <div class="why-inner">
      <div class="why-left reveal">
        <div class="section-tag">Why Your Vote Matters</div>
        <h2 class="section-title">Lead with Wisdom,<br />Not Just Popularity</h2>
        <p class="section-sub">The CLAS Council shapes your academic experience — from programs to advocacy to resources. Choosing the right leaders starts with you.</p>
        <div class="quote-block">
          "A true leader is not the one who gets the most likes, but the one who fights for students even when no one is watching."
        </div>
      </div>
      <div class="why-right">
        <div class="why-item reveal">
          <div class="why-icon">🎯</div>
          <div>
            <div class="why-item-title">Vote for Integrity</div>
            <p class="why-item-desc">Look beyond campaign posters. Research each candidate's track record, their platform specifics, and whether their promises are realistic and actionable.</p>
          </div>
        </div>
        <div class="why-item reveal" style="transition-delay:0.1s">
          <div class="why-icon">🤝</div>
          <div>
            <div class="why-item-title">Your Voice, Your Future</div>
            <p class="why-item-desc">The council decides on scholarship policies, academic complaints, and student events. Your vote shapes who makes those decisions for the next year.</p>
          </div>
        </div>
        <div class="why-item reveal" style="transition-delay:0.18s">
          <div class="why-icon">🔒</div>
          <div>
            <div class="why-item-title">Secure & Anonymous</div>
            <p class="why-item-desc">Powered by Suffra Tech's end-to-end encryption. Your ballot is completely private — no one can see who you voted for, guaranteed by zero-knowledge architecture.</p>
          </div>
        </div>
        <div class="why-item reveal" style="transition-delay:0.24s">
          <div class="why-icon">⚡</div>
          <div>
            <div class="why-item-title">Results You Can Trust</div>
            <p class="why-item-desc">Live tally with full audit trail. Every vote is verified, timestamped, and publicly auditable — so you know the results are real and tamper-free.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- DEVELOPERS -->
  <section id="developers">
    <div class="dev-section-inner">
      <div class="dev-section-header reveal">
        <div class="section-tag">Our Team</div>
        <h2 class="section-title">Meet the Developers</h2>
        <p class="section-sub mx-auto" style="text-align:center;">
          SuffraTech was built by a passionate team of student developers at UCC dedicated to making school democracy digital, safe, and accessible for everyone.
        </p>
      </div>
      <div class="dev-cards-grid">

        <div class="dev-tab-card reveal">
          <div class="dev-tab-avatar" style="background: linear-gradient(135deg, #0f3d2e, #1a6b46)">RA</div>
          <div class="dev-tab-info">
            <div class="dev-tab-name">Railhie Ampater</div>
            <div class="dev-tab-role">Lead / Backend Developer</div>
            <p class="dev-tab-bio">Vote your candidates wisely — choose a candidate who has passion. Your vote and our vote will change the future of the students. We vote as one.</p>
            <div class="dev-tab-socials">
              <a href="https://www.facebook.com/rairai.ampiii" target="_blank" class="dev-tab-social">𝒇 Facebook</a>
            </div>
          </div>
        </div>

        <div class="dev-tab-card reveal" style="transition-delay:0.08s">
          <div class="dev-tab-avatar" style="background: linear-gradient(135deg, #1a6b46, #2d9e68)">ASL</div>
          <div class="dev-tab-info">
            <div class="dev-tab-name">Aira Ysabelle Lugay</div>
            <div class="dev-tab-role">Frontend Developer</div>
            <p class="dev-tab-bio">Designed and built the user interface from the ground up — every button, card, and animation you see is her work. Dedicated to creating experiences that are both beautiful and accessible.</p>
            <div class="dev-tab-socials">
              <a href="https://www.facebook.com/wyxzabelle" target="_blank" class="dev-tab-social">𝒇 Facebook</a>
            </div>
          </div>
        </div>

        <div class="dev-tab-card reveal" style="transition-delay:0.14s">
          <div class="dev-tab-avatar" style="background: linear-gradient(135deg, #b45309, #d97706)">GS</div>
          <div class="dev-tab-info">
            <div class="dev-tab-name">Gwen Alexis Santiago</div>
            <div class="dev-tab-role">UI Designer</div>
            <p class="dev-tab-bio">Built the server-side logic, database structure, and API endpoints that power SuffraTech. Ensures every vote is recorded accurately, securely, and without duplication.</p>
            <div class="dev-tab-socials">
              <a href="https://www.facebook.com/n.wennie" target="_blank" class="dev-tab-social">𝒇 Facebook</a>
            </div>
          </div>
        </div>

        <div class="dev-tab-card reveal" style="transition-delay:0.2s">
          <div class="dev-tab-avatar" style="background: linear-gradient(135deg, #7c3aed, #a855f7)">FD</div>
          <div class="dev-tab-info">
            <div class="dev-tab-name">Richzelia Faith Degamon</div>
            <div class="dev-tab-role">Documentation</div>
            <p class="dev-tab-bio">Led the design research, wireframing, and visual identity of SuffraTech. Focused on making the platform intuitive for every student, regardless of their tech experience.</p>
            <div class="dev-tab-socials">
              <a href="https://www.facebook.com/sweetdreamsrichzelia" target="_blank" class="dev-tab-social">𝒇 Facebook</a>
            </div>
          </div>
        </div>

        <div class="dev-tab-card reveal" style="transition-delay:0.26s">
          <div class="dev-tab-avatar" style="background: linear-gradient(135deg, #0e7490, #0ea5e9)">PS</div>
          <div class="dev-tab-info">
            <div class="dev-tab-name">Princess Saliwan</div>
            <div class="dev-tab-role">Documentation</div>
            <p class="dev-tab-bio">Tested every feature, filed every bug, and wrote the documentation that keeps the team aligned. The last line of defense before anything reaches the student body.</p>
            <div class="dev-tab-socials">
              <a href="https://www.facebook.com/princess.saliwan12" target="_blank" class="dev-tab-social">𝒇 Facebook</a>
            </div>
          </div>
        </div>

      </div>
    </div>
  </section>

  <!-- POWERED BY -->
  <section id="powered">
    <div class="powered-inner reveal">
      <div style="font-size:0.73rem;letter-spacing:0.12em;text-transform:uppercase;color:var(--text-dim);margin-bottom:20px;">Powered by</div>
      <div class="powered-logo">
        <div class="powered-logo-icon" style="background:transparent;box-shadow:none;">
          <img src="suffratech.png" alt="SuffraTech" style="width:36px;height:36px;object-fit:contain;mix-blend-mode:screen;" />
        </div>
        <span class="powered-logo-name">Suffra<span>Tech</span></span>
      </div>
      <p class="powered-sub">
        This election is powered by Suffra Tech — secure, transparent, and scalable democratic infrastructure built for institutions of all sizes.
      </p>
      <div class="powered-tags">
        <span class="powered-tag">End-to-End Encrypted</span>
        <span class="powered-tag">Zero-Knowledge Architecture</span>
        <span class="powered-tag">GDPR Compliant</span>
        <span class="powered-tag">ISO 27001</span>
        <span class="powered-tag">Audit Ready</span>
        <span class="powered-tag">Real-Time Tally</span>
      </div>
    </div>
  </section>

  <!-- FOOTER -->
  <footer>
    <div class="footer-top">
      <a href="#home" class="nav-logo">
        <div class="logo-icon" style="background:transparent;box-shadow:none;">
          <img src="suffratech.png" alt="SuffraTech" style="width:34px;height:34px;object-fit:contain;mix-blend-mode:screen;" />
        </div>
        <span class="logo-text">Suffra<span>Tech</span> × CLAS UCC</span>
      </a>
      <div class="footer-links">
        <a href="#home">Home</a>
        <a href="#facebook">CLAS Pages</a>
        <a href="#whyvote">Why Vote</a>
        <a href="#developers">Developers</a>
        <a href="#powered">About</a>
        <a href="#">Privacy Policy</a>
      </div>
    </div>
    <div class="footer-bottom">
      <p>© 2026 SuffraTech — CLAS Council Election, University of Caloocan City South Campus.</p>
      <p style="color:var(--text-dim);font-size:0.82rem;">This is an official election portal. All votes are encrypted and anonymized.</p>
    </div>
  </footer>

  <script>
    /* ── CUSTOM CURSOR ── */
    const dot = document.getElementById('cursorDot');
    const ring = document.getElementById('cursorRing');
    let mx = 0,
      my = 0,
      rx = 0,
      ry = 0;
    document.addEventListener('mousemove', e => {
      mx = e.clientX;
      my = e.clientY;
      dot.style.left = mx + 'px';
      dot.style.top = my + 'px';
    });
    (function animRing() {
      rx += (mx - rx) * 0.14;
      ry += (my - ry) * 0.14;
      ring.style.left = rx + 'px';
      ring.style.top = ry + 'px';
      requestAnimationFrame(animRing);
    })();
    document.querySelectorAll('a,button,.powered-tag,.why-item,.fb-card,.dev-tab-card').forEach(el => {
      el.addEventListener('mouseenter', () => document.body.classList.add('cursor-hover'));
      el.addEventListener('mouseleave', () => document.body.classList.remove('cursor-hover'));
    });

    /* ── PROGRESS BAR ── */
    const progressBar = document.getElementById('progress-bar');
    window.addEventListener('scroll', () => {
      const pct = window.scrollY / (document.body.scrollHeight - window.innerHeight) * 100;
      progressBar.style.width = Math.min(pct, 100) + '%';
    });

    /* ── NAVBAR SCROLL ── */
    const navbar = document.getElementById('navbar');
    const scrollTopBtn = document.getElementById('scroll-top');
    window.addEventListener('scroll', () => {
      navbar.classList.toggle('scrolled', window.scrollY > 40);
      scrollTopBtn.classList.toggle('show', window.scrollY > 400);
    });
    scrollTopBtn.addEventListener('click', () => window.scrollTo({
      top: 0,
      behavior: 'smooth'
    }));

    /* ── MOBILE NAV ── */
    const menuBtn = document.getElementById('menuBtn');
    const drawer = document.getElementById('mobileDrawer');
    let drawerOpen = false;
    menuBtn.addEventListener('click', () => {
      drawerOpen = !drawerOpen;
      drawer.classList.toggle('open', drawerOpen);
      const spans = menuBtn.querySelectorAll('span');
      if (drawerOpen) {
        spans[0].style.transform = 'rotate(45deg) translate(5px,5px)';
        spans[1].style.opacity = '0';
        spans[2].style.transform = 'rotate(-45deg) translate(5px,-5px)';
      } else {
        spans.forEach(s => {
          s.style.transform = '';
          s.style.opacity = '';
        });
      }
    });

    function closeDrawer() {
      drawerOpen = false;
      drawer.classList.remove('open');
      const spans = menuBtn.querySelectorAll('span');
      spans.forEach(s => {
        s.style.transform = '';
        s.style.opacity = '';
      });
    }

    /* ── TYPEWRITER ── */
    const phrases = ['change everything.', 'shape tomorrow.', 'define your future.', 'make history.'];
    let pi = 0,
      ci = 0,
      del = false;
    const tw = document.getElementById('typewriter');

    function type() {
      const full = phrases[pi];
      if (!del) {
        tw.textContent = full.slice(0, ++ci);
        if (ci === full.length) {
          del = true;
          setTimeout(type, 2200);
          return;
        }
        setTimeout(type, 70);
      } else {
        tw.textContent = full.slice(0, --ci);
        if (ci === 0) {
          del = false;
          pi = (pi + 1) % phrases.length;
          setTimeout(type, 400);
          return;
        }
        setTimeout(type, 38);
      }
    }
    setTimeout(type, 900);

    /* ── PARTICLES CANVAS ── */
    const canvas = document.getElementById('particleCanvas');
    const ctx = canvas.getContext('2d');
    let W, H, particles = [];

    function resize() {
      W = canvas.width = window.innerWidth;
      H = canvas.height = window.innerHeight;
    }
    resize();
    window.addEventListener('resize', resize);
    class Particle {
      constructor() {
        this.reset();
      }
      reset() {
        this.x = Math.random() * W;
        this.y = Math.random() * H;
        this.r = Math.random() * 1.5 + 0.3;
        this.speed = Math.random() * 0.3 + 0.05;
        this.opacity = Math.random() * 0.4 + 0.1;
        this.color = Math.random() > 0.6 ? '#c9a227' : '#1e4d30';
        this.drift = (Math.random() - 0.5) * 0.3;
      }
      update() {
        this.y -= this.speed;
        this.x += this.drift;
        this.opacity -= 0.0008;
        if (this.opacity <= 0 || this.y < -10) this.reset();
      }
      draw() {
        ctx.save();
        ctx.globalAlpha = this.opacity;
        ctx.fillStyle = this.color;
        ctx.beginPath();
        ctx.arc(this.x, this.y, this.r, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();
      }
    }
    for (let i = 0; i < 80; i++) particles.push(new Particle());

    function animParticles() {
      ctx.clearRect(0, 0, W, H);
      particles.forEach(p => {
        p.update();
        p.draw();
      });
      requestAnimationFrame(animParticles);
    }
    animParticles();

    /* ── CARD PARTICLES ── */
    const cp = document.getElementById('cardParticles');

    function spawnCardParticle() {
      const el = document.createElement('div');
      el.className = 'card-particle';
      el.style.cssText = `left:${Math.random()*100}%;bottom:0;animation-duration:${Math.random()*2+2}s;animation-delay:${Math.random()}s;width:${Math.random()*3+1}px;height:${Math.random()*3+1}px;`;
      cp.appendChild(el);
      setTimeout(() => el.remove(), 4000);
    }
    setInterval(spawnCardParticle, 400);

    /* ── 3D TILT CARD ── */
    const card = document.getElementById('heroCard');
    card.addEventListener('mousemove', e => {
      const r = card.getBoundingClientRect();
      const cx = (e.clientX - r.left) / r.width - 0.5;
      const cy = (e.clientY - r.top) / r.height - 0.5;
      card.style.transform = `perspective(800px) rotateY(${cx * 10}deg) rotateX(${-cy * 6}deg) translateY(-6px) scale(1.01)`;
    });
    card.addEventListener('mouseleave', () => {
      card.style.transform = '';
      card.style.transition = 'transform 0.6s cubic-bezier(0.22,1,0.36,1)';
      setTimeout(() => card.style.transition = '', 600);
    });

    /* ── FB CARD RADIAL HOVER ── */
    document.querySelectorAll('.fb-card').forEach(card => {
      card.addEventListener('mousemove', e => {
        const r = card.getBoundingClientRect();
        card.style.setProperty('--mx', ((e.clientX - r.left) / r.width * 100) + '%');
        card.style.setProperty('--my', ((e.clientY - r.top) / r.height * 100) + '%');
      });
    });

    /* ── SCROLL REVEAL ── */
    const reveals = document.querySelectorAll('.reveal');
    const revealObs = new IntersectionObserver((entries) => {
      entries.forEach(e => {
        if (e.isIntersecting) {
          e.target.classList.add('visible');
          revealObs.unobserve(e.target);
        }
      });
    }, {
      threshold: 0.1
    });
    reveals.forEach(el => revealObs.observe(el));

    /* ── RIPPLE EFFECT ── */
    document.querySelectorAll('.ripple-btn').forEach(btn => {
      btn.addEventListener('click', function(e) {
        const r = this.getBoundingClientRect();
        const ripple = document.createElement('span');
        ripple.className = 'ripple';
        const size = Math.max(r.width, r.height);
        ripple.style.cssText = `width:${size}px;height:${size}px;left:${e.clientX-r.left-size/2}px;top:${e.clientY-r.top-size/2}px;`;
        this.appendChild(ripple);
        setTimeout(() => ripple.remove(), 600);
      });
    });
  </script>
</body>

</html>