<?php
session_start();
require_once 'firebase-config.php';

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

$fareSettings = getFareSettings();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fare Settings - PerdaRide</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f6fa;
        }

        .container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background: white;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            padding: 20px 0;
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid #eee;
            margin-bottom: 20px;
        }

        .sidebar-header h2 {
            color: #333;
            font-size: 18px;
        }

        .nav-item {
            padding: 15px 20px;
            display: flex;
            align-items: center;
            color: #666;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .nav-item:hover, .nav-item.active {
            background: #fff5f2;
            color: #ff6b35;
            border-left-color: #ff6b35;
        }

        .nav-item i {
            margin-right: 10px;
            width: 20px;
        }

        .main-content {
            flex: 1;
            padding: 20px;
        }

        .header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .header h1 {
            color: #333;
            margin-bottom: 6px;
        }

        .header p {
            color: #666;
            font-size: 14px;
        }

        .content-area {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 24px;
            max-width: 480px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }

        .form-group select,
        .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 15px;
        }

        .rm-input {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .rm-input span {
            color: #666;
            font-weight: 600;
        }

        .save-btn {
            width: 100%;
            padding: 12px;
            background: #ff6b35;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }

        .save-btn:hover {
            background: #e55a2b;
        }

        .save-message {
            display: none;
            margin-bottom: 16px;
            padding: 10px 12px;
            border-radius: 5px;
            font-size: 14px;
        }

        .save-message.success {
            display: block;
            background: #d4edda;
            color: #155724;
        }

        .save-message.error {
            display: block;
            background: #f8d7da;
            color: #721c24;
        }

        .preview-box {
            margin-top: 20px;
            padding: 14px;
            background: #f8f9fa;
            border-radius: 5px;
            font-size: 14px;
            color: #333;
        }

        .logout-btn {
            position: absolute;
            bottom: 20px;
            left: 20px;
            right: 20px;
            padding: 10px;
            background: #ff6b35;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            display: block;
        }

        .logout-btn:hover {
            background: #e55a2b;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Administrator</h2>
            </div>

            <a href="dashboard.php" class="nav-item">
                <i class="fas fa-tachometer-alt"></i>
                Rides Management
            </a>
            <a href="driver-requests.php" class="nav-item">
                <i class="fas fa-user-plus"></i>
                Driver Request
            </a>
            <a href="users-drivers.php" class="nav-item">
                <i class="fas fa-users"></i>
                Users & Driver
            </a>
            <a href="driver-commission.php" class="nav-item">
                <i class="fas fa-dollar-sign"></i>
                Driver Commission
            </a>
            <a href="fare-settings.php" class="nav-item active">
                <i class="fas fa-money-bill-wave"></i>
                Fare Settings
            </a>
            <a href="customer-feedback.php" class="nav-item">
                <i class="fas fa-comments"></i>
                Customer Feedback
            </a>
            <a href="reports.php" class="nav-item">
                <i class="fas fa-chart-line"></i>
                Reports
            </a>

            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>

        <div class="main-content">
            <div class="header">
                <h1>Fare Settings</h1>
                <p>Set the base fare, price per KM, and booking fee for each ride category. The app reads these values directly, so changes apply immediately.</p>
            </div>

            <div class="content-area">
                <div id="saveMessage" class="save-message"></div>

                <form id="fareForm">
                    <div class="form-group">
                        <label for="category">Ride Category</label>
                        <select id="category" name="category">
                            <option value="categoryA">Category A (Standard)</option>
                            <option value="categoryB">Category B (Premium)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="baseFare">Base Fare</label>
                        <div class="rm-input">
                            <span>RM</span>
                            <input type="number" id="baseFare" name="baseFare" step="0.01" min="0" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="perKm">Price Per KM</label>
                        <div class="rm-input">
                            <span>RM</span>
                            <input type="number" id="perKm" name="perKm" step="0.01" min="0" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="bookingFee">Booking Fee</label>
                        <div class="rm-input">
                            <span>RM</span>
                            <input type="number" id="bookingFee" name="bookingFee" step="0.01" min="0" required>
                        </div>
                    </div>

                    <button type="submit" class="save-btn">Save Settings</button>
                </form>

                <div class="preview-box">
                    Example fare for a 10 KM ride: <strong>RM <span id="previewTotal">0.00</span></strong>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Both categories' settings, loaded once from the server -- switching
        // the dropdown just swaps between these two in-memory objects, no
        // extra request needed.
        const fareSettings = <?php echo json_encode($fareSettings); ?>;

        const categorySelect = document.getElementById('category');
        const baseFareInput = document.getElementById('baseFare');
        const perKmInput = document.getElementById('perKm');
        const bookingFeeInput = document.getElementById('bookingFee');
        const previewTotal = document.getElementById('previewTotal');
        const saveMessage = document.getElementById('saveMessage');

        function fillForm(category) {
            const settings = fareSettings[category];
            baseFareInput.value = settings.baseFare.toFixed(2);
            perKmInput.value = settings.perKm.toFixed(2);
            bookingFeeInput.value = settings.bookingFee.toFixed(2);
            updatePreview();
        }

        function updatePreview() {
            const base = parseFloat(baseFareInput.value) || 0;
            const perKm = parseFloat(perKmInput.value) || 0;
            const fee = parseFloat(bookingFeeInput.value) || 0;
            previewTotal.textContent = (base + (10 * perKm) + fee).toFixed(2);
        }

        categorySelect.addEventListener('change', function () {
            fillForm(this.value);
        });
        [baseFareInput, perKmInput, bookingFeeInput].forEach(function (input) {
            input.addEventListener('input', updatePreview);
        });

        fillForm(categorySelect.value);

        document.getElementById('fareForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const payload = {
                category: categorySelect.value,
                baseFare: parseFloat(baseFareInput.value),
                perKm: parseFloat(perKmInput.value),
                bookingFee: parseFloat(bookingFeeInput.value)
            };

            fetch('save-fare-settings.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        fareSettings[payload.category] = {
                            baseFare: payload.baseFare,
                            perKm: payload.perKm,
                            bookingFee: payload.bookingFee
                        };
                        saveMessage.textContent = 'Fare settings saved successfully.';
                        saveMessage.className = 'save-message success';
                    } else {
                        saveMessage.textContent = 'Error: ' + (data.message || 'Failed to save settings.');
                        saveMessage.className = 'save-message error';
                    }
                })
                .catch(() => {
                    saveMessage.textContent = 'Error saving fare settings. Please try again.';
                    saveMessage.className = 'save-message error';
                });
        });
    </script>
</body>
</html>
