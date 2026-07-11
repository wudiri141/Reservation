<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';

date_default_timezone_set(app_config()['timezone']);

$pdo = db();
initialize_schema($pdo);

function resolve_logo_src(): ?string
{
    foreach (['logo.jpg', 'logo.png', 'logo.webp', 'logo.jpeg', 'logo.svg'] as $file) {
        $path = __DIR__ . '/' . $file;
        if (is_file($path)) {
            return '/' . $file;
        }
    }

    return null;
}

$logoSrc = resolve_logo_src();
$success = ($_GET['success'] ?? '') === '1';
$reservedDate = (string) ($_GET['date'] ?? '');
$error = '';

$fullName = '';
$email = '';
$phone = '';
$eventDate = '';
$eventType = 'General';
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $eventDate = trim((string) ($_POST['event_date'] ?? ''));

    if ($fullName === '' || $email === '' || $phone === '' || $eventDate === '') {
        $error = 'Please complete all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT * FROM reservation_dates WHERE event_date = ? FOR UPDATE');
            $stmt->execute([$eventDate]);
            $slot = $stmt->fetch();

            if (!$slot) {
                throw new RuntimeException('Selected date is not available.');
            }

            if ((int) $slot['remaining_slots'] <= 0 || $slot['status'] === 'closed') {
                throw new RuntimeException('No slots available for this date.');
            }

            $checkDuplicate = $pdo->prepare('SELECT id FROM reservations WHERE email = ? AND event_date = ? LIMIT 1');
            $checkDuplicate->execute([$email, $eventDate]);

            if ($checkDuplicate->fetch()) {
                throw new RuntimeException('You already reserved this date with this email address.');
            }

            $reservationId = generate_reservation_id();

            $insert = $pdo->prepare(
                'INSERT INTO reservations (reservation_id, full_name, email, phone, event_date, event_type, message) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([$reservationId, $fullName, $email, $phone, $eventDate, $eventType, $message ?: null]);

            $remainingSlots = max(0, (int) $slot['remaining_slots'] - 1);
            $status = $remainingSlots === 0 ? 'closed' : 'open';

            $update = $pdo->prepare('UPDATE reservation_dates SET remaining_slots = ?, status = ? WHERE event_date = ?');
            $update->execute([$remainingSlots, $status, $eventDate]);

            $pdo->commit();

            $customerBody = build_email_template(
                'Reservation Confirmed',
                'Your reservation has been received successfully. Below is the summary of your booking.',
                [
                    'Name' => $fullName,
                    'Email' => $email,
                    'Phone' => $phone,
                    'Selected Date' => format_date($eventDate),
                ],
                'We look forward to seeing you at THE SPACE.'
            );

            $adminBody = build_email_template(
                'New Reservation Received',
                'A new reservation has been submitted through the website.',
                [
                    'Customer Name' => $fullName,
                    'Customer Email' => $email,
                    'Customer Phone' => $phone,
                    'Selected Date' => format_date($eventDate),
                ],
                'Please review the reservation details in the admin dashboard.'
            );

            send_email($email, 'Reservation Confirmation', $customerBody);
            send_email(app_config()['smtp']['admin_email'], 'New Reservation Received', $adminBody);

            header('Location: ?success=1&date=' . urlencode($eventDate));
            exit;
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $throwable->getMessage();
        }
    }
}

$dates = $pdo->query(
    'SELECT event_date, total_slots, remaining_slots, status FROM reservation_dates ORDER BY event_date ASC'
)->fetchAll();

$openDates = array_values(array_filter($dates, static function (array $date): bool {
    return (int) $date['remaining_slots'] > 0 && $date['status'] === 'open';
}));
$hasAnyDates = count($dates) > 0;
$hasAvailableDates = count($openDates) > 0;
$defaultDate = $hasAvailableDates ? $openDates[0]['event_date'] : ($hasAnyDates ? $dates[0]['event_date'] : '');
$selectedDate = $eventDate !== '' ? $eventDate : ($reservedDate !== '' ? $reservedDate : $defaultDate);
$selectedDateLabel = $selectedDate !== '' ? format_date($selectedDate) : 'your selected date';
$dateMap = [];

foreach ($dates as $date) {
    $dateMap[$date['event_date']] = [
        'label' => format_date($date['event_date']),
        'remaining' => (int) $date['remaining_slots'],
        'open' => (int) $date['remaining_slots'] > 0 && $date['status'] === 'open',
    ];
}

$selectedDateRemaining = $selectedDate !== '' && isset($dateMap[$selectedDate]) ? $dateMap[$selectedDate]['remaining'] : 0;
$selectedDateOpen = $selectedDate !== '' && isset($dateMap[$selectedDate]) ? $dateMap[$selectedDate]['open'] : false;
$selectedSpotText = $selectedDate !== ''
    ? ($selectedDateOpen ? $selectedDateLabel . ' - ' . $selectedDateRemaining . ' Spots Left' : $selectedDateLabel . ' - Fully Booked')
    : 'No dates available';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>THE SPACE - WorkSpace Preview</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #ffffff;
            color: #1a1a1a;
            line-height: 1.6;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 24px;
        }

        /* Header */
        .header {
            padding: 24px 0;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 20px;
            font-weight: 800;
            color: #1a1a1a;
            text-decoration: none;
        }

        .logo-icon {
            width: 24px;
            height: 24px;
            border: 3px solid #1a1a1a;
            border-radius: 4px;
            position: relative;
        }

        .logo-icon::before {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 8px;
            height: 8px;
            background: #1a1a1a;
            border-radius: 2px;
        }
        .logo-image {
            width: auto;
            height: 70px;
            max-width: 220px;
            object-fit: contain;
            display: block;
        }

        .footer .logo-image {
            height: 34px;
        }

        /* Hero Section */
        .hero {
            padding: 24px 0 48px;
        }

        .workspace-label {
            color: #e07b39;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 12px;
        }

        .hero h1 {
            font-size: 42px;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 16px;
            max-width: 500px;
        }

        .hero p {
            color: #666;
            font-size: 16px;
            max-width: 400px;
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #1a1a1a;
            color: #fff;
            padding: 14px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-primary:hover {
            background: #333;
        }

        .btn-primary .arrow {
            font-size: 18px;
        }

        /* Countdown */
        .countdown {
            display: flex;
            padding: 32px 0;
            border-top: 1px solid #eee;
            border-bottom: 1px solid #eee;
            margin: 32px 0;
        }

        .countdown-item {
            flex: 1;
            text-align: center;
            border-right: 1px solid #eee;
        }

        .countdown-item:last-child {
            border-right: none;
        }

        .countdown-number {
            font-size: 36px;
            font-weight: 700;
            color: #1a1a1a;
        }

        .countdown-label {
            font-size: 14px;
            color: #888;
            margin-top: 4px;
        }

        /* Space View Section */
        .section-title {
            color: #e07b39;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 16px;
        }

        .image-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            grid-template-areas:
                'wide wide tall'
                'card1 card2 tall';
            gap: 16px;
            margin-bottom: 16px;
        }

        .space-image {
            width: 100%;
            height: 200px;
            background: #f0f0f0;
            border-radius: 12px;
            object-fit: cover;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 12px;
        }

        .space-image.large {
            height: 240px;
        }

        .space-image.small {
            height: 160px;
        }

        .space-image.wide {
            grid-area: wide;
            height: 240px;
        }

        .space-image.tall {
            grid-area: tall;
            height: 416px;
        }

        .space-image.card1 {
            grid-area: card1;
            height: 160px;
        }

        .space-image.card2 {
            grid-area: card2;
            height: 160px;
        }

        /* Booking Section */
        .booking-section {
            margin: 48px 0;
        }

        .booking-card {
            background: #fff;
            border: 1px solid #e5e5e5;
            border-radius: 16px;
            padding: 24px;
        }

        .booking-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .booking-header h3 {
            font-size: 18px;
            font-weight: 600;
        }

        .close-btn {
            width: 32px;
            height: 32px;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            background: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: #666;
        }

        .choose-day-label {
            font-size: 11px;
            font-weight: 500;
            color: #888;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }

        .day-selector {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }

        .day-btn {
            padding: 12px 20px;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            background: #fff;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .day-btn:hover {
            border-color: #1a1a1a;
        }

        .day-btn.active {
            background: #1a1a1a;
            color: #fff;
            border-color: #1a1a1a;
        }

        .day-btn.full {
            background: #f5f5f5;
            color: #888;
            cursor: not-allowed;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .full-badge {
            background: #fee;
            color: #e74c3c;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 600;
        }

        .spots-info {
            color: #e07b39;
            font-size: 13px;
            margin: 16px 0 24px;
        }

        .form-section {
            border-top: 1px solid #eee;
            padding-top: 20px;
        }

        .form-section-title {
            font-size: 11px;
            font-weight: 500;
            color: #888;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 11px;
            font-weight: 500;
            color: #888;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #1a1a1a;
        }

        .form-group input::placeholder {
            color: #bbb;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #1a1a1a;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 120px;
        }

        .confirm-btn {
            width: 100%;
            padding: 16px;
            background: #1a1a1a;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
            transition: background 0.2s;
        }

        .confirm-btn:hover {
            background: #333;
        }

        .confirm-note {
            text-align: center;
            font-size: 12px;
            color: #999;
            margin-top: 16px;
        }

        /* Success Modal */
        .success-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .success-modal.active {
            display: flex;
        }

        .success-content {
            background: #f8f8f6;
            border-radius: 24px;
            padding: 48px;
            text-align: center;
            max-width: 480px;
            margin: 20px;
        }

        .success-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 24px;
            position: relative;
        }

        .success-badge {
            width: 80px;
            height: 80px;
            background: #1e7d45;
            clip-path: polygon(50% 0%, 61% 11%, 80% 5%, 80% 25%, 98% 35%, 88% 50%, 98% 65%, 80% 75%, 80% 95%, 61% 89%, 50% 100%, 39% 89%, 20% 95%, 20% 75%, 2% 65%, 12% 50%, 2% 35%, 20% 25%, 20% 5%, 39% 11%);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .success-badge svg {
            width: 32px;
            height: 32px;
            color: #fff;
        }

        .success-circle {
            position: absolute;
            top: -8px;
            left: -8px;
            width: 96px;
            height: 96px;
            border: 2px solid #1e7d45;
            border-radius: 50%;
            opacity: 0.5;
        }

        .success-content h2 {
            font-size: 36px;
            font-weight: 800;
            margin-bottom: 12px;
        }

        .success-content p {
            color: #666;
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 32px;
        }

        .reference-box {
            background: #fdf8ec;
            border-radius: 12px;
            padding: 24px;
        }

        .reference-label {
            font-size: 11px;
            font-weight: 500;
            color: #d4a84b;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        .reference-code {
            font-size: 32px;
            font-weight: 700;
            color: #d4a84b;
            letter-spacing: 2px;
        }

        /* Footer */
        .footer {
            background: #f5f5f5;
            padding: 48px 0 0;
            margin-top: 48px;
        }

        .footer-content {
            text-align: center;
            padding-bottom: 32px;
        }

        .footer .logo {
            justify-content: center;
            margin-bottom: 16px;
        }

        .footer-tagline {
            color: #666;
            font-size: 14px;
            margin-bottom: 24px;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }

        .footer-address {
            font-size: 13px;
            color: #888;
            margin-bottom: 4px;
        }

        .footer-contact {
            font-size: 13px;
            color: #888;
            margin-bottom: 4px;
        }

        .footer-contact a {
            color: #888;
            text-decoration: none;
        }

        .social-links {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-top: 20px;
        }

        .social-link {
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1a1a1a;
            text-decoration: none;
            font-size: 18px;
        }

        .copyright {
            background: #1a1a1a;
            color: #fff;
            text-align: center;
            padding: 16px;
            font-size: 12px;
        }

        .copyright span {
            opacity: 0.8;
        }


        .notice {
            padding: 14px 16px;
            border-radius: 14px;
            margin-bottom: 16px;
            line-height: 1.6;
            border: 1px solid transparent;
        }

        .notice.success {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #065f46;
        }

        .notice.error {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }

        /* Responsive */
        @media (max-width: 600px) {
            .hero h1 {
                font-size: 32px;
            }

            .countdown-number {
                font-size: 28px;
            }

            .image-grid {
                grid-template-columns: 1fr;
                grid-template-areas:
                    'wide'
                    'tall'
                    'card1'
                    'card2';
            }

            .day-selector {
                gap: 6px;
            }

            .day-btn {
                padding: 10px 14px;
                font-size: 13px;
            }

            .success-content {
                padding: 32px 24px;
            }

            .success-content h2 {
                font-size: 28px;
            }

            .reference-code {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="container">
        <header class="header">
            <a href="#" class="logo">
                <?php if ($logoSrc !== null): ?>
                    <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="THE SPACE" class="logo-image">
                <?php else: ?>
                    <div class="logo-icon" aria-hidden="true"></div>
                <?php endif; ?>
            </a>
        </header>

        <!-- Hero Section -->
        <section class="hero">
            <p class="workspace-label">WorkSpace</p>
            <h1>Come see the space before the doors open.</h1>
            <p>Reserve your spot for a private preview of WorkSpace — a new kind of workspace designed for how you actually work. Limited visits available.</p>
            <a href="#booking" class="btn-primary">
                Reserve a spot <span class="arrow">→</span>
            </a>
        </section>

        <!-- Countdown -->
        <div class="countdown">
            <div class="countdown-item">
                <div class="countdown-number" id="days">02</div>
                <div class="countdown-label">Days</div>
            </div>
            <div class="countdown-item">
                <div class="countdown-number" id="hours">14</div>
                <div class="countdown-label">Hours</div>
            </div>
            <div class="countdown-item">
                <div class="countdown-number" id="minutes">02</div>
                <div class="countdown-label">Minutes</div>
            </div>
            <div class="countdown-item">
                <div class="countdown-number" id="seconds">02</div>
                <div class="countdown-label">Seconds</div>
            </div>
        </div>

        <!-- Space View Section -->
        <section class="space-view">
            <p class="section-title">The Space View</p>
            
            <div class="image-grid">
                <img src="space1.jpg" alt="Workspace view 1" class="space-image wide" onerror="this.style.background='#e8e8e8'; this.alt='space1.jpg'">
                <img src="space2.jpg" alt="Workspace view 2" class="space-image tall" onerror="this.style.background='#e8e8e8'; this.alt='space2.jpg'">
                <img src="space3.jpg" alt="Workspace view 3" class="space-image card1" onerror="this.style.background='#e8e8e8'; this.alt='space3.jpg'">
                <img src="space4.jpg" alt="Workspace view 4" class="space-image card2" onerror="this.style.background='#e8e8e8'; this.alt='space4.jpg'">
            </div>
        </section>

        <!-- Booking Section -->
        <section class="booking-section" id="booking">
            <p class="section-title">Booking</p>

            <?php if ($error !== ''): ?>
                <div class="notice error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (!$hasAvailableDates): ?>
                <div class="notice error"><?php echo $hasAnyDates ? 'No slots available now. All event dates are fully booked.' : 'No event dates have been added yet. Please check back later.'; ?></div>
            <?php endif; ?>

            <div class="booking-card">
                <div class="booking-header">
                    <h3>Pick your visit</h3>
                    <button type="button" class="close-btn" aria-label="Scroll to top" onclick="window.scrollTo({ top: 0, behavior: 'smooth' })">×</button>
                </div>

                <form class="booking-form" method="post" id="reservationForm">
                    <p class="choose-day-label">CHOOSE A DAY</p>

                    <div class="day-selector" id="daySelector">
                        <?php if ($hasAnyDates): ?>
                            <?php foreach ($dates as $date): ?>
                                <?php
                                    $dateKey = $date['event_date'];
                                    $isOpen = (int) $date['remaining_slots'] > 0 && $date['status'] === 'open';
                                    $isActive = $selectedDate === $dateKey;
                                    $shortLabel = date('D j', strtotime($dateKey));
                                ?>
                                <button
                                    type="button"
                                    class="day-btn<?php echo $isActive ? ' active' : ''; ?><?php echo $isOpen ? '' : ' full'; ?>"
                                    data-date="<?php echo htmlspecialchars($dateKey); ?>"
                                    data-label="<?php echo htmlspecialchars(format_date($dateKey)); ?>"
                                    data-remaining="<?php echo (int) $date['remaining_slots']; ?>"
                                    data-status="<?php echo htmlspecialchars($date['status']); ?>"
                                    <?php echo $isOpen ? '' : 'disabled'; ?>
                                >
                                    <?php echo htmlspecialchars($shortLabel); ?>
                                    <?php if (!$isOpen): ?>
                                        <span class="full-badge">Full</span>
                                    <?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <button type="button" class="day-btn full" disabled>No Dates Available <span class="full-badge">Full</span></button>
                        <?php endif; ?>
                    </div>

                    <p class="spots-info" id="spotsInfo"><?php echo htmlspecialchars($selectedSpotText); ?></p>

                    <div class="form-section">
                        <p class="form-section-title">YOUR DETAILS</p>
                        <input type="hidden" name="event_date" id="event_date" value="<?php echo htmlspecialchars($selectedDate); ?>">

                        <div class="form-group">
                            <label for="full_name">YOUR NAME</label>
                            <input type="text" id="full_name" name="full_name" placeholder="Your Name" value="<?php echo htmlspecialchars($fullName); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="email">EMAIL ADDRESS</label>
                            <input type="email" id="email" name="email" placeholder="Yourname@Gmail.Com" value="<?php echo htmlspecialchars($email); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="phone">PHONE NUMBER</label>
                            <input type="tel" id="phone" name="phone" placeholder="08062527698" value="<?php echo htmlspecialchars($phone); ?>" required>
                        </div>

                        <button class="confirm-btn" type="submit"<?php echo $hasAvailableDates ? '' : ' disabled'; ?>>
                            Confirm Visit <span class="arrow">→</span>
                        </button>

                        <p class="confirm-note">You'll Receive A Confirmation Email Shortly.</p>
                    </div>
                </form>
            </div>
        </section>
    </div>
    <!-- Footer -->
        <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <a href="#" class="logo">
                    <?php if ($logoSrc !== null): ?>
                        <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="THE SPACE" class="logo-image">
                    <?php else: ?>
                        <div class="logo-icon" aria-hidden="true"></div>
                    <?php endif; ?>
                </a>
                <p class="footer-tagline">Together, we're creating opportunities, empowering lives, and making a difference—one story at a time.</p>
                <p class="footer-address">No 24, Road G, Malali New Extension</p>
                <p class="footer-contact">+2348139414056</p>
                <p class="footer-contact"><a href="mailto:info@thespace.com">info@thespace.com</a></p>
                
                <div class="social-links">
                    <a href="#" class="social-link" title="LinkedIn">
                        <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>
                    </a>
                    <a href="#" class="social-link" title="X (Twitter)">
                        <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                    </a>
                    <a href="#" class="social-link" title="Facebook">
                        <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                    </a>
                    <a href="#" class="social-link" title="Instagram">
                        <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                    </a>
                    <a href="#" class="social-link" title="YouTube" style="color: #e74c3c;">
                        <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                    </a>
                </div>
            </div>
        </div>
        <div class="copyright">
            <span>© 2026 COPYRIGHT THE SPACE. ALL RIGHTS RESERVED</span>
        </div>
    </footer>

    <!-- Success Modal -->
    <div class="success-modal<?php echo $success ? ' active' : ''; ?>" id="successModal">
        <div class="success-content">
            <div class="success-icon">
                <div class="success-circle"></div>
                <div class="success-badge">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
            </div>
            <h2>You're In.</h2>
            <p>We've reserved your spot for <?php echo htmlspecialchars($selectedDateLabel); ?>. Check your inbox for confirmation details.</p>
        </div>
    </div>

    <script>
        // Countdown Timer
        function updateCountdown() {
            // Set target date to 2 days from now
            const now = new Date();
            const target = new Date(now.getTime() + 0 * 00 * 00 * 00 * 0000 + 00 * 00 * 00 * 0000);

            const diff = target - now;

            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);

            document.getElementById('days').textContent = String(days).padStart(2, '0');
            document.getElementById('hours').textContent = String(hours).padStart(2, '0');
            document.getElementById('minutes').textContent = String(minutes).padStart(2, '0');
            document.getElementById('seconds').textContent = String(seconds).padStart(2, '0');
        }

        // Update every second
        setInterval(updateCountdown, 1000);
        updateCountdown();

        // Day Selection
        const dayBtns = document.querySelectorAll('.day-btn:not(.full)');
        const eventDateInput = document.getElementById('event_date');
        const spotsInfo = document.getElementById('spotsInfo');

        function updateSelectedDay(button) {
            dayBtns.forEach((btn) => btn.classList.remove('active'));
            button.classList.add('active');

            const dateKey = button.dataset.date;
            eventDateInput.value = dateKey;
            spotsInfo.textContent = `${button.dataset.label} - ${button.dataset.remaining} Spots Left`;
        }

        dayBtns.forEach((btn) => {
            btn.addEventListener('click', function() {
                updateSelectedDay(this);
            });
        });

        // Close modal when clicking outside
        const successModal = document.getElementById('successModal');
        successModal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
            }
        });
    </script>
</body>
</html>
