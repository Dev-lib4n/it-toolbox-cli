# IT Toolbox CLI (`it-toolbox`)

[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![Laravel Zero](https://img.shields.io/badge/Laravel%20Zero-11.x-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel-zero.com/)
[![CI Status](https://github.com/Dev-lib4n/it-toolbox-cli/actions/workflows/ci.yml/badge.svg)](https://github.com/Dev-lib4n/it-toolbox-cli/actions/workflows/ci.yml)
[![MIT License](https://img.shields.io/badge/License-MIT-green.svg?style=flat-square)](./LICENSE)

> **Language / Idioma:** **[🇺🇸 English](./README.md)** | **[🇧🇷 Português (Brasil)](./README.pt-BR.md)**

---

## Overview

**IT Toolbox CLI (`it-toolbox`)** is a cross-platform, interactive terminal utility (TUI/CLI) built with **PHP 8.3+**, **Laravel Zero**, and **Laravel Prompts**. Designed for **IT Support Technicians, Help Desk Engineers, and System Administrators**, it unifies daily troubleshooting, hardware/OS diagnostics, print spooler recovery, network health checks, and safe temporary file cleanup across both **Windows** and **Linux** (distribution-agnostic) environments from a single portable binary.

---

## Features

- **🩺 Full Machine Health Check & Score (`health:check`):**
  - Calculates an objective **0–100 Health Score** across RAM usage, disk storage, internet/DNS connectivity, print queue state, and critical OS logs, classifying the workstation into **Healthy (`80–100`)**, **Warning (`50–79`)**, or **Critical (`0–49`)** with visual progress bar, checklist (`✅` / `⚠️` / `❌`), and actionable remediation recommendations.
- **🖥 System & Hardware Diagnostics (`system`):**
  - Inspect OS metadata, kernel version, architecture, hostname, and system uptime.
  - Real-time CPU model, core/thread count, load percentage, and physical RAM usage.
  - Storage volume capacity, used/free space, and usage percentages across NTFS/ext4/btrfs/xfs filesystems.
  - Top resource-consuming processes viewer with interactive frozen process termination (`PID` kill).
  - **Critical OS Logs & Recent Errors (`system:logs`):** Inspect critical and error events recorded in the last 24 hours (`Get-WinEvent` on Windows / `journalctl -p err` & `/var/log/syslog` on Linux).
- **🖨 Printers & Spooler Management (`printer`):**
  - List installed printers, driver details, ports/URIs, default status, and queue states (`PowerShell Get-Printer` on Windows / `CUPS lpstat` on Linux).
  - One-step print queue purge and spooler service restart (`net stop/start spooler` & `PRINTERS` cleanup on Windows / `cancel -a` & `systemctl restart cups` on Linux).
- **🌐 Network & Connectivity Diagnostics (`network`):**
  - Active network interface inspection (`IPv4`, `IPv6`, `MAC`, link status).
  - Automated connectivity health check (Default Gateway ping, external ICMP latency to `8.8.8.8` / `1.1.1.1`, and DNS resolution verification).
  - One-click DNS cache flush and DHCP IP lease renewal.
  - Active listening `TCP`/`UDP` ports and owning processes scanner (`Get-NetTCPConnection` / `ss -tulnp`).
- **🧹 System Maintenance & Cleanup (`maintenance`):**
  - Safe cleanup of user and system temporary directories (`%TEMP%`, `C:\Windows\Temp`, `/tmp`, `/var/tmp`, `~/.cache/thumbnails`), gracefully skipping locked/in-use files and reporting freed disk space.
- **📄 Diagnostic Reports & Export (`report`):**
  - Export complete hardware, OS, printer, and network diagnostic snapshots to structured `.md` and `.json` files for ticketing systems and audit logs.
- **⬆️ Over-the-Air Self-Update (`self:update`):**
  - Automatically queries GitHub Releases for newer versions and atomically updates the running standalone binary (`.exe`, Linux ELF, or `.phar`) in-place.
- **🌍 Native Bilingual Interface (`i18n`):**
  - Full support for **English (`en`)** and **Brazilian Portuguese (`pt_BR`)**, selectable via `--lang=` flag or dynamically inside the interactive numeric TUI menu.

---

## Architecture

The project strictly separates presentation (TUI commands), orchestration, and operating-system-specific execution using the **Adapter/Driver Pattern** and **Factory Pattern**. All external OS commands are executed safely through `Symfony\Component\Process\Process` using argument arrays to prevent Command Injection.

```mermaid
flowchart TD
    User(["IT Technician / SysAdmin"]) --> CLI["CLI Entrypoint (it-toolbox)"]
    CLI --> Menu["ToolboxMenuCommand / Subcommands (Laravel Prompts)"]
    Menu --> I18n["Translator Service (lang/en & lang/pt_BR)"]
    Menu --> Factory["SystemDriverFactory::make(PHP_OS_FAMILY)"]

    Factory -->|Windows| WinDriver["WindowsDriver (SystemDriverInterface)"]
    Factory -->|Linux| LinDriver["LinuxDriver (SystemDriverInterface)"]

    WinDriver --> WinProcess["Symfony Process (Argument Arrays)"]
    LinDriver --> LinProcess["Symfony Process / Native Filesystem"]

    WinProcess --> WinAPI["PowerShell CIM / WMI / net / ipconfig / taskkill"]
    LinProcess --> LinAPI["/proc / POSIX / CUPS (lpstat/cancel) / ip / ss / resolvectl"]

    WinAPI --> DTO["ActionResult Readonly DTO"]
    LinAPI --> DTO
    DTO --> Menu
```

---

## Installation & Usage

Choose the installation method that best fits your scenario:

#### Option 1: Native Standalone Binary (Zero Dependencies — No PHP or Composer Required)

Ideal for field IT technicians and target workstations. Download the self-contained native executable (powered by `static-php-cli` `micro.sfx`) directly from the [GitHub Releases](https://github.com/Dev-lib4n/it-toolbox-cli/releases) page and run it immediately without installing PHP or Composer:

**Windows (x64):**
```powershell
# Download 'it-toolbox-windows-x64.exe' from GitHub Releases and run:
.\it-toolbox-windows-x64.exe

# Or launch directly in your preferred language:
.\it-toolbox-windows-x64.exe --lang=en
.\it-toolbox-windows-x64.exe --lang=pt_BR
```

**Linux (x64):**
```bash
# Download 'it-toolbox-linux-x64' from GitHub Releases, grant execution permission, and run:
chmod +x it-toolbox-linux-x64
./it-toolbox-linux-x64

# (Optional) Install globally in PATH:
sudo mv it-toolbox-linux-x64 /usr/local/bin/it-toolbox
it-toolbox
```

#### Option 2: Portable PHAR (Requires PHP 8.3+)

If your machine already has **PHP 8.3+** installed, download the cross-platform `it-toolbox` PHAR archive from [GitHub Releases](https://github.com/Dev-lib4n/it-toolbox-cli/releases):

```bash
php it-toolbox
```

#### Option 3: From Source (Requires PHP 8.3+ & Composer 2.x)

For developers who want to customize the source code or run the automated test suite:

```bash
# 1. Clone the repository
git clone https://github.com/Dev-lib4n/it-toolbox-cli.git
cd it-toolbox-cli

# 2. Install dependencies via Composer
composer install
```

> **Note:** Administrator privileges (Windows) or `sudo` (Linux) are recommended for privileged system operations (spooler restart, system temp cleanup, and DHCP renewal).

### Interactive TUI Mode

Launch the interactive dashboard. If `--lang` is omitted, an interactive language selector is presented on startup:

```bash
# Launch with interactive language selector
php it-toolbox

# Launch directly in English
php it-toolbox --lang=en

# Launch directly in Brazilian Portuguese
php it-toolbox --lang=pt_BR
```

### Direct CLI Subcommands

Every module can also be executed directly in headless/CLI scripts or terminal sessions:

| Command | Module | Description | Options |
| :--- | :--- | :--- | :--- |
| `php it-toolbox menu` | Core TUI | Launch the interactive numeric TUI main menu (default command) | `--lang=en\|pt_BR`, `--once` |
| `php it-toolbox health:check` | Health | Run a full machine health audit with a 0–100 score and recommendations | `--export`, `--lang=en\|pt_BR` |
| `php it-toolbox system:info` | System | Display OS, CPU, RAM, and disk health metrics | `--lang=en\|pt_BR` |
| `php it-toolbox system:top` | System | List top processes by resource usage and optionally kill a PID | `--limit=10`, `--lang=en\|pt_BR` |
| `php it-toolbox system:logs` | System | Inspect critical and error OS log events from the last 24 hours | `--limit=15`, `--lang=en\|pt_BR` |
| `php it-toolbox printer:list` | Printers | List installed printers, drivers, ports, and status | `--lang=en\|pt_BR` |
| `php it-toolbox printer:clear` | Printers | Restart print spooler / CUPS and purge stuck print jobs | `--force`, `--lang=en\|pt_BR` |
| `php it-toolbox network:check` | Network | Inspect network interfaces and run ping/DNS health checks | `--lang=en\|pt_BR` |
| `php it-toolbox network:flush` | Network | Flush DNS resolver cache and renew DHCP lease | `--force`, `--lang=en\|pt_BR` |
| `php it-toolbox network:ports` | Network | List active listening TCP/UDP ports and owning processes | `--lang=en\|pt_BR` |
| `php it-toolbox clean:temp` | Maintenance | Safely purge temporary files and report freed space | `--force`, `--lang=en\|pt_BR` |
| `php it-toolbox report:export` | Reports | Export full system diagnostic report to `.md` and/or `.json` | `--format=md\|json\|both`, `--output=DIR`, `--lang=en\|pt_BR` |
| `php it-toolbox self:update` | Core CLI | Check GitHub Releases and self-update the running executable | `--check`, `--lang=en\|pt_BR` |

---

## Build & Tests

### Compiling Standalone PHAR & Native Binaries

Compile the application into a portable `.phar` (`builds/it-toolbox`) or bundle it with `static-php-cli` (`micro.sfx`) into zero-dependency native executables (`builds/it-toolbox-linux-x64` and `builds/it-toolbox-windows-x64.exe`):

```bash
# Build portable PHAR only
php it-toolbox app:build it-toolbox --build-version=1.0.0

# Build PHAR + Native Linux & Windows x64 standalone binaries
./scripts/build-standalone.sh 1.0.0
```

### Running the Test Suite

The test suite is powered by **Pest PHP** and mocks OS-level processes so all tests run deterministically on both Windows and Linux CI runners:

```bash
# Run all Unit and Feature tests
./vendor/bin/pest

# Check code style with Laravel Pint
./vendor/bin/pint --test
```

---

## Tech Stack & Design Patterns

- **Language & Runtime:** PHP 8.3+ (`declare(strict_types=1)`, typed properties, `match` expressions, `readonly` classes)
- **Micro-Framework:** [Laravel Zero](https://laravel-zero.com/)
- **Terminal UI (TUI):** [Laravel Prompts](https://laravel.com/docs/prompts) (`select`, `table`, `spin`, `confirm`, `info`, `warning`, `error`)
- **Process Execution:** `Symfony\Component\Process\Process` (strictly array-based arguments for Command Injection prevention)
- **Internationalization:** `Illuminate\Translation` (`lang/en` & `lang/pt_BR`)
- **Architectural Patterns:**
  - **Adapter / Driver Pattern:** `SystemDriverInterface` implemented by `WindowsDriver` and `LinuxDriver`.
  - **Factory Pattern:** `SystemDriverFactory` dynamically resolves the driver via `PHP_OS_FAMILY`.
  - **Data Transfer Objects (DTO):** Immutable `readonly class ActionResult` for standardized operation outcomes.
- **Testing & QA:** Pest PHP, Mockery, Laravel Pint, and GitHub Actions CI across Ubuntu and Windows runners.

---

## License

This project is open-sourced software licensed under the [MIT License](./LICENSE).
Copyright (c) 2026 **Ian Carlos Pinheiro Libanio da Silva**.
