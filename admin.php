<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

session_start();
date_default_timezone_set(app_config()['timezone']);

$pdo = db();
initialize_schema($pdo);

$config = app_config();
$isLoggedIn = ($_SESSION['is_admin'] ?? false) === true;
$message = '';
$error = '';

if (isset($_GET['logout'])) {
    unset($_SESSION['is_admin']);
    session_destroy();
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === $config['admin']['username'] && $password === $config['admin']['password']) {
        $_SESSION['is_admin'] = true;
        header('Location: admin.php');
        exit;
    }

    $error = 'Invalid admin login details.';
}

if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = (string) $_POST['action'];

        if ($action === 'add_date') {
            $eventDate = (string) ($_POST['event_date'] ?? '');
            $totalSlots = (int) ($_POST['total_slots'] ?? 10);
            if ($eventDate === '') {
                throw new RuntimeException('Select a date to add.');
            }
            save_reservation_date($pdo, $eventDate, $totalSlots, $totalSlots, 'open');
            $message = 'Date saved successfully.';
        }

        if ($action === 'generate_dates') {
            $startDate = (string) ($_POST['start_date'] ?? '');
            $count = (int) ($_POST['count'] ?? 1);
            $intervalDays = (int) ($_POST['interval_days'] ?? 1);
            $slotsPerDate = (int) ($_POST['slots_per_date'] ?? 10);

            generate_date_range($startDate, $count, $intervalDays, $slotsPerDate, $pdo);
            $message = 'Automatic dates generated successfully.';
        }

        if ($action === 'update_date') {
            $eventDate = (string) ($_POST['event_date'] ?? '');
            $totalSlots = max(1, (int) ($_POST['total_slots'] ?? 10));
            $remainingSlots = max(0, (int) ($_POST['remaining_slots'] ?? 0));
            $status = (string) ($_POST['status'] ?? 'open');
            $addSlots = max(0, (int) ($_POST['add_slots'] ?? 0));

            if ($eventDate === '') {
                throw new RuntimeException('Missing date.');
            }

            if ($addSlots > 0) {
                adjust_reservation_slots($pdo, $eventDate, $addSlots);
                $message = 'Slots increased successfully.';
            } else {
                save_reservation_date($pdo, $eventDate, $totalSlots, $remainingSlots, $status);
                $message = 'Date updated successfully.';
            }
        }
    } catch (Throwable $throwable) {
        $error = $throwable->getMessage();
    }
}

$dates = get_reservation_dates($pdo);
$stats = [
    'total' => count($dates),
    'open' => count(array_filter($dates, static fn (array $date): bool => (int) $date['remaining_slots'] > 0)),
    'closed' => count(array_filter($dates, static fn (array $date): bool => (int) $date['remaining_slots'] === 0)),
];

?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin - Event Reservation Website</title>
    <style><?php echo app_styles(); ?></style>
  </head>
  <body>
    <main class="page">
      <section class="card admin-card">
        <div class="admin-head">
          <div>
            <p class="eyebrow">Admin Dashboard</p>
            <h1>Manage Reservation Dates</h1>
            <p class="subtitle">Add dates, generate automatic ranges, and adjust slot counts anytime.</p>
          </div>
          <?php if ($isLoggedIn): ?>
            <a class="button secondary admin-logout" href="?logout=1">Logout</a>
          <?php endif; ?>
        </div>

        <?php if (!$isLoggedIn): ?>
          <form class="form admin-login" method="post">
            <input type="hidden" name="login" value="1" />
            <div class="field">
              <label for="username">Username</label>
              <input id="username" name="username" type="text" required />
            </div>
            <div class="field">
              <label for="password">Password</label>
              <input id="password" name="password" type="password" required />
            </div>
            <button class="button" type="submit">Login</button>
          </form>
        <?php else: ?>
          <?php if ($message !== ''): ?>
            <div class="notice success"><?php echo htmlspecialchars($message); ?></div>
          <?php endif; ?>
          <?php if ($error !== ''): ?>
            <div class="notice error"><?php echo htmlspecialchars($error); ?></div>
          <?php endif; ?>

          <div class="admin-stats">
            <div class="stat-box"><span>Total Dates</span><strong><?php echo $stats['total']; ?></strong></div>
            <div class="stat-box"><span>Open Dates</span><strong><?php echo $stats['open']; ?></strong></div>
            <div class="stat-box"><span>Closed Dates</span><strong><?php echo $stats['closed']; ?></strong></div>
          </div>

          <div class="admin-grid">
            <section class="panel admin-panel">
              <span class="badge">Add Single Date</span>
              <form class="form admin-form" method="post">
                <input type="hidden" name="action" value="add_date" />
                <div class="field">
                  <label for="event_date">Date</label>
                  <input id="event_date" name="event_date" type="date" required />
                </div>
                <div class="field">
                  <label for="total_slots">Slots</label>
                  <input id="total_slots" name="total_slots" type="number" min="1" value="10" required />
                </div>
                <button class="button" type="submit">Save Date</button>
              </form>
            </section>

            <section class="panel admin-panel">
              <span class="badge">Automatic Dates</span>
              <form class="form admin-form" method="post">
                <input type="hidden" name="action" value="generate_dates" />
                <div class="field">
                  <label for="start_date">Start Date</label>
                  <input id="start_date" name="start_date" type="date" required />
                </div>
                <div class="field">
                  <label for="count">Number of Dates</label>
                  <input id="count" name="count" type="number" min="1" value="5" required />
                </div>
                <div class="field">
                  <label for="interval_days">Interval Days</label>
                  <input id="interval_days" name="interval_days" type="number" min="1" value="1" required />
                </div>
                <div class="field">
                  <label for="slots_per_date">Slots Per Date</label>
                  <input id="slots_per_date" name="slots_per_date" type="number" min="1" value="10" required />
                </div>
                <button class="button" type="submit">Generate Dates</button>
              </form>
            </section>
          </div>

          <section class="panel admin-panel">
            <span class="badge">Existing Dates</span>
            <div class="table-wrap">
              <table class="admin-table">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Total Slots</th>
                    <th>Remaining</th>
                    <th>Status</th>
                    <th>Add Slots</th>
                    <th>Update</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($dates as $date): ?>
                    <?php $formId = 'update-' . preg_replace('/[^a-z0-9]+/i', '-', $date['event_date']); ?>
                    <tr>
                        <td><?php echo htmlspecialchars(format_date($date['event_date'])); ?></td>
                        <td>
                          <input class="admin-input" form="<?php echo htmlspecialchars($formId); ?>" type="number" name="total_slots" min="1" value="<?php echo (int) $date['total_slots']; ?>" />
                        </td>
                        <td>
                          <input class="admin-input" form="<?php echo htmlspecialchars($formId); ?>" type="number" name="remaining_slots" min="0" value="<?php echo (int) $date['remaining_slots']; ?>" />
                        </td>
                        <td>
                          <select class="admin-input" form="<?php echo htmlspecialchars($formId); ?>" name="status">
                            <option value="open" <?php echo $date['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="closed" <?php echo $date['status'] === 'closed' ? 'selected' : ''; ?>>Closed</option>
                          </select>
                        </td>
                        <td>
                          <input class="admin-input" form="<?php echo htmlspecialchars($formId); ?>" type="number" name="add_slots" min="0" value="0" />
                        </td>
                        <td>
                          <form id="<?php echo htmlspecialchars($formId); ?>" method="post">
                            <input type="hidden" name="action" value="update_date" />
                            <input type="hidden" name="event_date" value="<?php echo htmlspecialchars($date['event_date']); ?>" />
                            <button class="button small" type="submit">Save</button>
                          </form>
                        </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        <?php endif; ?>
      </section>
    </main>
  </body>
</html>
