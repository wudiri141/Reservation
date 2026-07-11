<?php

declare(strict_types=1);

function app_config(): array
{
    static $config;

    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }

    return $config;
}

function db(): PDO
{
    static $pdo;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config()['db'];
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['name'],
        $config['charset']
    );

    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function initialize_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS reservation_dates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_date DATE NOT NULL UNIQUE,
            total_slots TINYINT UNSIGNED NOT NULL DEFAULT 10,
            remaining_slots TINYINT UNSIGNED NOT NULL DEFAULT 10,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS reservations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reservation_id VARCHAR(32) NOT NULL UNIQUE,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(50) NOT NULL,
            event_date DATE NOT NULL,
            event_type VARCHAR(100) NOT NULL,
            message TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_email_date (email, event_date),
            CONSTRAINT fk_reservation_date FOREIGN KEY (event_date) REFERENCES reservation_dates(event_date) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $count = (int) $pdo->query('SELECT COUNT(*) FROM reservation_dates')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO reservation_dates (event_date, total_slots, remaining_slots, status) VALUES (?, 10, 10, "open")'
        );

        foreach (app_config()['seed_dates'] as $date) {
            $stmt->execute([$date]);
        }
    }
}

function format_date(string $date): string
{
    return date('F j, Y', strtotime($date));
}

function generate_reservation_id(): string
{
    return 'RSV-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function get_reservation_dates(PDO $pdo): array
{
    return $pdo->query(
        'SELECT event_date, total_slots, remaining_slots, status, created_at, updated_at FROM reservation_dates ORDER BY event_date ASC'
    )->fetchAll();
}

function save_reservation_date(PDO $pdo, string $eventDate, int $totalSlots, int $remainingSlots, string $status): void
{
    $totalSlots = max(1, $totalSlots);
    $remainingSlots = max(0, min($remainingSlots, $totalSlots));
    $status = $remainingSlots === 0 ? 'closed' : ($status === 'closed' ? 'closed' : 'open');

    $stmt = $pdo->prepare(
        'INSERT INTO reservation_dates (event_date, total_slots, remaining_slots, status) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE total_slots = VALUES(total_slots), remaining_slots = VALUES(remaining_slots), status = VALUES(status)'
    );
    $stmt->execute([$eventDate, $totalSlots, $remainingSlots, $status]);
}

function adjust_reservation_slots(PDO $pdo, string $eventDate, int $addSlots): void
{
    $addSlots = max(0, $addSlots);
    if ($addSlots === 0) {
        return;
    }

    $stmt = $pdo->prepare('SELECT total_slots, remaining_slots FROM reservation_dates WHERE event_date = ? FOR UPDATE');
    $stmt->execute([$eventDate]);
    $row = $stmt->fetch();

    if (!$row) {
        throw new RuntimeException('Selected date does not exist.');
    }

    $totalSlots = (int) $row['total_slots'] + $addSlots;
    $remainingSlots = (int) $row['remaining_slots'] + $addSlots;

    save_reservation_date($pdo, $eventDate, $totalSlots, $remainingSlots, 'open');
}

function generate_date_range(string $startDate, int $count, int $intervalDays, int $slotsPerDate, PDO $pdo): void
{
    $count = max(1, $count);
    $intervalDays = max(1, $intervalDays);
    $slotsPerDate = max(1, $slotsPerDate);
    $timestamp = strtotime($startDate);

    if ($timestamp === false) {
        throw new RuntimeException('Invalid start date.');
    }

    for ($index = 0; $index < $count; $index++) {
        $date = date('Y-m-d', strtotime('+' . ($index * $intervalDays) . ' days', $timestamp));
        save_reservation_date($pdo, $date, $slotsPerDate, $slotsPerDate, 'open');
    }
}

function app_styles(): string
{
    return <<<'CSS'
:root {
  --bg: #f3f4f6;
  --card: #ffffff;
  --text: #111827;
  --muted: #6b7280;
  --line: #e5e7eb;
  --accent: #2563eb;
  --accent-dark: #1d4ed8;
  --success-bg: #ecfdf5;
  --success-border: #a7f3d0;
  --success-text: #065f46;
  --error-bg: #fef2f2;
  --error-border: #fecaca;
  --error-text: #991b1b;
  --shadow: 0 18px 50px rgba(15, 23, 42, 0.12);
}

* {
  box-sizing: border-box;
}

body {
  margin: 0;
  min-height: 100vh;
  background: linear-gradient(180deg, #f8fafc 0%, var(--bg) 100%);
  color: var(--text);
  font-family: Arial, Helvetica, sans-serif;
}

.page {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 24px;
}

.card {
  width: min(100%, 760px);
  background: var(--card);
  border: 1px solid var(--line);
  border-radius: 24px;
  box-shadow: var(--shadow);
  padding: 28px;
}

.panel {
  background: var(--card);
  border: 1px solid var(--line);
  border-radius: 24px;
  box-shadow: var(--shadow);
}

.eyebrow {
  margin: 0 0 10px;
  color: var(--accent);
  font-size: 0.9rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  border-radius: 999px;
  background: rgba(37, 99, 235, 0.08);
  color: var(--accent);
  font-weight: 700;
  font-size: 0.95rem;
}

h1 {
  margin: 0;
  font-size: clamp(2rem, 5vw, 3rem);
  letter-spacing: -0.03em;
}

.subtitle {
  margin: 12px 0 24px;
  color: var(--muted);
  line-height: 1.6;
}

.notice {
  padding: 14px 16px;
  border-radius: 14px;
  margin-bottom: 16px;
  line-height: 1.6;
}

.notice.success {
  background: var(--success-bg);
  border: 1px solid var(--success-border);
  color: var(--success-text);
}

.notice.error {
  background: var(--error-bg);
  border: 1px solid var(--error-border);
  color: var(--error-text);
}

.slot-list {
  display: grid;
  gap: 12px;
  margin-bottom: 20px;
}

.slot-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 16px;
  border-radius: 14px;
  border: 1px solid var(--line);
  background: #fafafa;
}

.slot-item.open {
  background: #f8fbff;
}

.slot-item.closed {
  background: #fff7f7;
}

.slot-item strong {
  font-size: 0.95rem;
}

.form {
  display: grid;
  gap: 16px;
}

.field {
  display: grid;
  gap: 8px;
}

.field.full {
  grid-column: 1 / -1;
}

label {
  font-weight: 700;
  font-size: 0.95rem;
}

input,
select,
textarea {
  width: 100%;
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 14px 16px;
  font: inherit;
  background: #fff;
  color: var(--text);
  outline: none;
}

input:focus,
select:focus,
textarea:focus {
  border-color: rgba(37, 99, 235, 0.65);
  box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
}

textarea {
  resize: vertical;
}

.button {
  width: 100%;
  border: none;
  border-radius: 14px;
  padding: 15px 18px;
  background: linear-gradient(135deg, var(--accent), var(--accent-dark));
  color: #fff;
  font-weight: 700;
  cursor: pointer;
}

.button:hover {
  filter: brightness(1.02);
}

.button:disabled {
  cursor: not-allowed;
  opacity: 0.7;
  filter: none;
}

.button.small {
  width: auto;
  padding: 10px 14px;
}

.button.secondary {
  display: inline-flex;
  width: auto;
  text-decoration: none;
}

.admin-card {
  width: min(100%, 1120px);
}

.admin-head {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
  margin-bottom: 20px;
}

.admin-login,
.admin-form {
  max-width: 560px;
}

.admin-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
  margin: 18px 0 22px;
}

.stat-box {
  border: 1px solid var(--line);
  border-radius: 16px;
  padding: 16px;
  background: #fafafa;
}

.stat-box span {
  display: block;
  color: var(--muted);
  margin-bottom: 6px;
}

.stat-box strong {
  font-size: 1.4rem;
}

.admin-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px;
  margin-bottom: 18px;
}

.admin-panel {
  padding: 20px;
}

.table-wrap {
  overflow-x: auto;
}

.admin-table {
  width: 100%;
  border-collapse: collapse;
  min-width: 860px;
}

.admin-table th,
.admin-table td {
  border-bottom: 1px solid var(--line);
  padding: 12px 10px;
  text-align: left;
  vertical-align: middle;
}

.admin-table th {
  font-size: 0.88rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--muted);
}

.admin-input {
  width: 100%;
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 10px 12px;
  background: #fff;
}

.admin-logout {
  align-self: center;
}

@media (min-width: 720px) {
  .form {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .field.full,
  .button {
    grid-column: 1 / -1;
  }
}

@media (max-width: 480px) {
  .card {
    padding: 20px;
    border-radius: 20px;
  }

  .slot-item {
    flex-direction: column;
    align-items: flex-start;
  }
}

@media (max-width: 980px) {
  .admin-grid,
  .admin-stats {
    grid-template-columns: 1fr;
  }

  .admin-head {
    flex-direction: column;
  }
}
CSS;
}
