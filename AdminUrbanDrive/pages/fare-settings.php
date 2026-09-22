<?php
session_start();
require_once 'firebase-config.php';

requireAdminSession();

// Fare Settings: one card per ride category, a live fare preview, and the
// change log (fareSettingsHistory) so every price change is traceable.

$fareSettings = getFareSettings();
$categoryLabels = fareCategoryLabels();
$fieldLabels = fareFieldLabels();
$history = getFareSettingsHistory(100);
$history = ensureFareHistoryBaseline($fareSettings, $history);
$lastChange = $history[0] ?? null;

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
function fareDateLabel($ms) {
    $seconds = firebaseTimestampToSeconds($ms);
    return $seconds > 0 ? date('d M Y, h:i A', $seconds) : '';
}

$historyCounts = ['categoryA' => 0, 'categoryB' => 0];
foreach ($history as $entry) {
    $cat = $entry['category'] ?? '';
    if (isset($historyCounts[$cat])) {
        $historyCounts[$cat]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fare Settings - Urban Drive</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --brand: #ff6b35;
            --brand-dark: #e55a2b;
            --brand-soft: #fff5f2;
            --brand-soft-2: #ffe5e0;
            --ink: #1e293b;
            --ink-2: #475569;
            --muted: #64748b;
            --line: #e2e8f0;
            --bg: #f5f7fa;
            --card: #ffffff;
            --green: #059669;
            --green-soft: #ecfdf5;
            --red: #dc2626;
            --red-soft: #fee2e2;
            --amber-soft: #fef3c7;
            --amber: #b45309;
            --radius: 16px;
            --shadow: 0 4px 20px rgba(15, 23, 42, 0.06);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg);
            color: var(--ink);
            min-height: 100vh;
        }
        .container { display: flex; min-height: 100vh; }

        /* Sidebar (same look as the other pages) */
        .sidebar {
            width: 280px;
            flex-shrink: 0;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            box-shadow: 4px 0 20px rgba(0,0,0,0.08);
            padding: 24px 0 96px;
            position: relative;
            border-right: 1px solid rgba(0,0,0,0.05);
        }
        .sidebar-header { padding: 0 24px 24px; border-bottom: 2px solid #f1f5f9; margin-bottom: 24px; }
        .sidebar-header h2 { color: var(--ink); font-size: 20px; font-weight: 700; letter-spacing: -0.5px; }
        .nav-item {
            padding: 14px 24px; display: flex; align-items: center; color: var(--muted); text-decoration: none;
            transition: all 0.2s ease; border-left: 4px solid transparent; font-weight: 500; font-size: 14px;
        }
        .nav-item:hover { background: linear-gradient(90deg, var(--brand-soft) 0%, var(--brand-soft-2) 100%); color: var(--brand); border-left-color: var(--brand); }
        .nav-item.active { background: linear-gradient(90deg, var(--brand-soft) 0%, var(--brand-soft-2) 100%); color: var(--brand); border-left-color: var(--brand); font-weight: 600; }
        .nav-item i { margin-right: 12px; width: 22px; font-size: 17px; text-align: center; }
        .logout-btn {
            position: absolute; bottom: 24px; left: 24px; right: 24px; padding: 13px;
            background: linear-gradient(135deg, var(--brand) 0%, #ff8c5a 100%); color: white; border: none; border-radius: 12px;
            cursor: pointer; text-decoration: none; text-align: center; display: flex; align-items: center; justify-content: center; gap: 8px;
            font-weight: 600; font-size: 14px; box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3); transition: all 0.2s ease;
        }
        .logout-btn:hover { background: linear-gradient(135deg, var(--brand-dark) 0%, var(--brand) 100%); transform: translateY(-1px); }

        /* Main */
        .main-content { flex: 1; padding: 32px; min-width: 0; }
        .page-head {
            display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .page-head h1 { font-size: 28px; font-weight: 700; letter-spacing: -0.5px; margin-bottom: 6px; }
        .page-head p { color: var(--muted); font-size: 14px; max-width: 640px; line-height: 1.5; }
        .last-change {
            background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 12px 16px;
            box-shadow: var(--shadow); font-size: 13px; color: var(--ink-2); display: flex; gap: 10px; align-items: center; min-width: 260px;
        }
        .last-change i { color: var(--brand); font-size: 16px; }
        .last-change strong { color: var(--ink); display: block; font-size: 13px; }
        .last-change span { display: block; color: var(--muted); font-size: 12px; margin-top: 2px; }

        /* Formula strip */
        .formula {
            background: linear-gradient(135deg, #fff7f3 0%, #fff 60%); border: 1px solid #ffd9cc; border-radius: var(--radius);
            padding: 16px 20px; margin-bottom: 20px; display: flex; align-items: center; gap: 18px; flex-wrap: wrap; box-shadow: var(--shadow);
        }
        .formula .f-label { font-size: 12px; text-transform: uppercase; letter-spacing: 0.6px; color: var(--brand-dark); font-weight: 700; }
        .formula .f-text { font-size: 15px; color: var(--ink); font-weight: 600; }
        .formula .f-text span { display: inline-block; background: #fff; border: 1px solid var(--line); border-radius: 8px; padding: 4px 10px; margin: 0 2px; font-weight: 600; }
        .formula .f-km { margin-left: auto; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--ink-2); }
        .formula input[type="range"] { width: 160px; accent-color: var(--brand); }
        .formula input[type="number"] { width: 72px; padding: 6px 8px; border: 1px solid var(--line); border-radius: 8px; font-size: 14px; text-align: right; }

        /* Category cards */
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px; margin-bottom: 24px; }
        .fare-card { background: var(--card); border-radius: var(--radius); box-shadow: var(--shadow); border: 1px solid var(--line); padding: 22px 24px; display: flex; flex-direction: column; gap: 18px; transition: border-color 0.2s ease; }
        .fare-card.dirty { border-color: var(--brand); }
        .fare-card-head { display: flex; gap: 14px; align-items: flex-start; }
        .cat-badge {
            width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center;
            font-size: 20px; font-weight: 800; color: #fff; background: linear-gradient(135deg, var(--brand) 0%, #ff8c5a 100%); flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(255, 107, 53, 0.25);
        }
        .fare-card[data-category="categoryB"] .cat-badge { background: linear-gradient(135deg, #1e293b 0%, #475569 100%); box-shadow: 0 4px 12px rgba(30, 41, 59, 0.25); }
        .fare-card-head h2 { font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .tier { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; padding: 3px 8px; border-radius: 999px; background: var(--brand-soft-2); color: var(--brand-dark); }
        .fare-card[data-category="categoryB"] .tier { background: #e2e8f0; color: var(--ink-2); }
        .cat-desc { color: var(--muted); font-size: 13px; margin-top: 3px; }
        .cat-meta { font-size: 12px; color: var(--muted); margin-top: 6px; display: flex; align-items: center; gap: 6px; }
        .cat-meta i { color: #94a3b8; }

        .fare-fields { display: grid; gap: 14px; }
        .field label { display: block; font-size: 13px; font-weight: 600; color: var(--ink-2); margin-bottom: 6px; }
        .rm { display: flex; align-items: center; border: 1.5px solid var(--line); border-radius: 10px; overflow: hidden; background: #fff; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
        .rm:focus-within { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.12); }
        .rm span { padding: 0 12px; color: var(--muted); font-weight: 700; font-size: 13px; background: #f8fafc; align-self: stretch; display: flex; align-items: center; border-right: 1px solid var(--line); }
        .rm input { flex: 1; border: none; padding: 11px 12px; font-size: 16px; font-weight: 600; color: var(--ink); outline: none; min-width: 0; background: transparent; }
        .rm input.changed { color: var(--brand-dark); }
        .field-hint { font-size: 12px; color: #94a3b8; margin-top: 5px; }
        .field-hint b { color: var(--muted); font-weight: 600; }

        .fare-example { background: #f8fafc; border: 1px dashed var(--line); border-radius: 12px; padding: 12px 14px; display: flex; justify-content: space-between; align-items: center; gap: 12px; font-size: 13px; color: var(--ink-2); }
        .fare-example .total { font-size: 20px; font-weight: 800; color: var(--ink); }
        .fare-example .total small { font-size: 12px; font-weight: 600; color: var(--muted); margin-right: 4px; }

        .fare-actions { display: flex; align-items: center; gap: 10px; }
        .fare-status { flex: 1; font-size: 13px; min-height: 18px; }
        .fare-status.ok { color: var(--green); }
        .fare-status.err { color: var(--red); }
        .fare-status.dirty { color: var(--amber); }
        .btn { border: none; border-radius: 10px; padding: 11px 16px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.15s ease; }
        .btn:disabled { opacity: 0.45; cursor: not-allowed; transform: none !important; box-shadow: none !important; }
        .btn-primary { background: linear-gradient(135deg, var(--brand) 0%, #ff8c5a 100%); color: #fff; box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3); }
        .btn-primary:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(255, 107, 53, 0.35); }
        .btn-ghost { background: #fff; color: var(--ink-2); border: 1.5px solid var(--line); }
        .btn-ghost:hover:not(:disabled) { border-color: #cbd5e1; color: var(--ink); }

        /* History */
        .history { background: var(--card); border-radius: var(--radius); box-shadow: var(--shadow); border: 1px solid var(--line); overflow: hidden; }
        .history-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 18px 24px; border-bottom: 1px solid var(--line); }
        .history-head h2 { font-size: 17px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .history-head h2 i { color: var(--brand); }
        .history-head .count { font-size: 12px; font-weight: 600; color: var(--muted); background: #f1f5f9; padding: 3px 9px; border-radius: 999px; }
        .history-filter { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--muted); }
        .history-filter select { padding: 8px 12px; border: 1.5px solid var(--line); border-radius: 10px; font-size: 13px; background: #fff; color: var(--ink); }
        .history table { width: 100%; border-collapse: collapse; }
        .history th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.6px; color: var(--muted); padding: 12px 24px; background: #f8fafc; border-bottom: 1px solid var(--line); }
        .history td { padding: 14px 24px; border-bottom: 1px solid #f1f5f9; vertical-align: top; font-size: 14px; }
        .history tr:last-child td { border-bottom: none; }
        .history tr.new-entry td { background: #fffaf7; animation: flash 2.5s ease forwards; }
        @keyframes flash { from { background: #ffe5e0; } to { background: transparent; } }
        .when strong { display: block; font-weight: 600; color: var(--ink); }
        .when span { font-size: 12px; color: var(--muted); }
        .who { color: var(--ink-2); font-size: 13px; word-break: break-all; }
        .cat-pill { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px; background: var(--brand-soft-2); color: var(--brand-dark); white-space: nowrap; }
        .cat-pill.b { background: #e2e8f0; color: var(--ink-2); }
        .chips { display: flex; flex-wrap: wrap; gap: 8px; }
        .chip { display: inline-flex; align-items: center; gap: 7px; background: #f8fafc; border: 1px solid var(--line); border-radius: 10px; padding: 6px 10px; font-size: 13px; }
        .chip .lbl { color: var(--muted); font-size: 12px; font-weight: 600; }
        .chip .from { color: #94a3b8; text-decoration: line-through; }
        .chip .to { font-weight: 700; color: var(--ink); }
        .chip .delta { font-size: 11px; font-weight: 700; padding: 2px 6px; border-radius: 6px; }
        .chip .delta.up { background: var(--red-soft); color: var(--red); }
        .chip .delta.down { background: var(--green-soft); color: var(--green); }
        .chip i { color: #cbd5e1; font-size: 11px; }
        .baseline-note { font-size: 12px; color: var(--muted); margin-top: 7px; display: flex; gap: 6px; align-items: center; }
        .baseline-note i { color: #94a3b8; }
        .chip.plain .to { font-weight: 700; }
        .empty { padding: 42px 24px; text-align: center; color: var(--muted); font-size: 14px; }
        .empty i { font-size: 30px; color: #cbd5e1; display: block; margin-bottom: 10px; }
        .table-wrap { overflow-x: auto; }

        @media (max-width: 1100px) {
            .formula .f-km { margin-left: 0; width: 100%; }
        }
        @media (max-width: 720px) {
            .main-content { padding: 20px 16px; }
            .history th, .history td { padding: 12px 16px; }
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
            <a href="rides-management.php" class="nav-item">
                <i class="fas fa-route"></i>
                All Rides
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
            <div class="page-head">
                <div>
                    <h1>Fare Settings</h1>
                    <p>Set the base fare, price per KM and booking fee for each ride category. The app reads these values when a rider previews a booking, so a saved change applies to the very next booking. Every change is recorded below.</p>
                </div>
                <div class="last-change" id="lastChange">
                    <i class="fas fa-history"></i>
                    <div>
                        <?php if ($lastChange): ?>
                            <strong>Last change: <?php echo h($categoryLabels[$lastChange['category']]['name'] ?? $lastChange['category']); ?></strong>
                            <span><?php echo h(fareDateLabel($lastChange['changedAt'])); ?> &middot; <?php echo h($lastChange['changedBy'] ?? 'admin'); ?></span>
                        <?php else: ?>
                            <strong>No changes recorded yet</strong>
                            <span>Prices are the built-in defaults until you save.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="formula">
                <div class="f-label">How a fare is calculated</div>
                <div class="f-text"><span>Base fare</span> + <span>Distance (km)</span> &times; <span>Price per KM</span> + <span>Booking fee</span></div>
                <div class="f-km">
                    <label for="previewKmRange">Preview distance</label>
                    <input type="range" id="previewKmRange" min="1" max="50" step="1" value="10">
                    <input type="number" id="previewKm" min="0.5" max="500" step="0.5" value="10"> km
                </div>
            </div>

            <div class="cards">
                <?php foreach ($categoryLabels as $key => $label): $s = $fareSettings[$key]; ?>
                <section class="fare-card" data-category="<?php echo h($key); ?>">
                    <header class="fare-card-head">
                        <div class="cat-badge"><?php echo h($label['letter']); ?></div>
                        <div>
                            <h2><?php echo h($label['name']); ?> <span class="tier"><?php echo h($label['tier']); ?></span></h2>
                            <div class="cat-desc">Shown in the app as <strong><?php echo h($label['package']); ?></strong> &middot; <?php echo h($label['passengers']); ?></div>
                            <div class="cat-meta" data-meta>
                                <i class="fas fa-clock"></i>
                                <?php if (!empty($s['updatedAt'])): ?>
                                    Updated <?php echo h(fareDateLabel($s['updatedAt'])); ?><?php if (!empty($s['updatedBy'])): ?> by <?php echo h($s['updatedBy']); ?><?php endif; ?>
                                <?php else: ?>
                                    Default values &middot; never saved
                                <?php endif; ?>
                            </div>
                        </div>
                    </header>

                    <div class="fare-fields">
                        <?php foreach ($fieldLabels as $field => $fieldLabel): ?>
                        <div class="field">
                            <label for="<?php echo h($key . '_' . $field); ?>"><?php echo h($fieldLabel); ?></label>
                            <div class="rm">
                                <span>RM</span>
                                <input type="number" id="<?php echo h($key . '_' . $field); ?>" data-field="<?php echo h($field); ?>" step="0.01" min="0" max="10000" value="<?php echo h(number_format((float) $s[$field], 2, '.', '')); ?>" required>
                            </div>
                            <div class="field-hint">Currently saved: <b data-saved-hint="<?php echo h($field); ?>">RM <?php echo h(number_format((float) $s[$field], 2)); ?></b></div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="fare-example">
                        <div>Example fare for <strong data-example-km>10</strong> km</div>
                        <div class="total"><small>RM</small><span data-example-total>0.00</span></div>
                    </div>

                    <div class="fare-actions">
                        <div class="fare-status" data-status></div>
                        <button type="button" class="btn btn-ghost" data-reset disabled><i class="fas fa-undo"></i> Reset</button>
                        <button type="button" class="btn btn-primary" data-save disabled><i class="fas fa-save"></i> Save changes</button>
                    </div>
                </section>
                <?php endforeach; ?>
            </div>

            <section class="history">
                <div class="history-head">
                    <h2><i class="fas fa-history"></i> Change history <span class="count" id="historyCount"><?php echo count($history); ?></span></h2>
                    <div class="history-filter">
                        <label for="historyFilter">Show</label>
                        <select id="historyFilter">
                            <option value="">All categories</option>
                            <?php foreach ($categoryLabels as $key => $label): ?>
                            <option value="<?php echo h($key); ?>"><?php echo h($label['name']); ?> (<?php echo (int) $historyCounts[$key]; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="table-wrap">
                    <table id="historyTable">
                        <thead>
                            <tr>
                                <th style="width: 190px;">When</th>
                                <th style="width: 150px;">Category</th>
                                <th style="width: 200px;">Changed by</th>
                                <th>What changed</th>
                            </tr>
                        </thead>
                        <tbody id="historyBody">
                            <?php foreach ($history as $entry): $cat = $entry['category'] ?? ''; $lbl = $categoryLabels[$cat] ?? null; ?>
                            <tr data-category="<?php echo h($cat); ?>" data-id="<?php echo h($entry['id']); ?>">
                                <td class="when">
                                    <strong><?php echo h(fareDateLabel($entry['changedAt'])); ?></strong>
                                    <span data-ago="<?php echo (int) $entry['changedAt']; ?>"></span>
                                </td>
                                <td><span class="cat-pill <?php echo $cat === 'categoryB' ? 'b' : ''; ?>"><?php echo h($lbl ? $lbl['letter'] : '?'); ?> &middot; <?php echo h($lbl ? $lbl['name'] : $cat); ?></span></td>
                                <td class="who"><?php echo h($entry['changedBy'] ?? 'admin'); ?></td>
                                <td>
                                    <?php if (($entry['kind'] ?? '') === 'baseline'): ?>
                                    <div class="chips">
                                        <?php foreach ($fieldLabels as $field => $fieldLabel): $v = (float) ($entry['after'][$field] ?? 0); ?>
                                        <span class="chip plain"><span class="lbl"><?php echo h($fieldLabel); ?></span><span class="to">RM <?php echo number_format($v, 2); ?></span></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="baseline-note"><i class="fas fa-info-circle"></i> Values on record when the change log was introduced. Edits made before this date were not tracked by the old page.</div>
                                    <?php else: ?>
                                    <div class="chips">
                                        <?php foreach ($entry['changes'] as $field => $change): if (!is_array($change)) continue; $from = (float) ($change['from'] ?? 0); $to = (float) ($change['to'] ?? 0); $delta = $to - $from; ?>
                                        <span class="chip">
                                            <span class="lbl"><?php echo h($fieldLabels[$field] ?? $field); ?></span>
                                            <span class="from">RM <?php echo number_format($from, 2); ?></span>
                                            <i class="fas fa-arrow-right"></i>
                                            <span class="to">RM <?php echo number_format($to, 2); ?></span>
                                            <span class="delta <?php echo $delta >= 0 ? 'up' : 'down'; ?>"><?php echo ($delta >= 0 ? '+' : '-') . number_format(abs($delta), 2); ?></span>
                                        </span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="empty" id="historyEmpty" <?php echo empty($history) ? '' : 'hidden'; ?>>
                        <i class="fas fa-receipt"></i>
                        No changes yet. When you save new fares, each change is listed here with the old and new values, who changed it, and when.
                    </div>
                </div>
            </section>
        </div>
    </div>

    <script>
        const CATEGORY_LABELS = <?php echo json_encode($categoryLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;
        const FIELD_LABELS = <?php echo json_encode($fieldLabels, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        // The values currently saved in Firebase; a card is "dirty" when its inputs differ from these.
        const saved = <?php echo json_encode($fareSettings, JSON_HEX_TAG | JSON_HEX_AMP); ?>;

        const previewKm = document.getElementById('previewKm');
        const previewKmRange = document.getElementById('previewKmRange');
        const historyBody = document.getElementById('historyBody');
        const historyEmpty = document.getElementById('historyEmpty');
        const historyCount = document.getElementById('historyCount');
        const historyFilter = document.getElementById('historyFilter');

        const money = n => 'RM ' + (Math.round((Number(n) || 0) * 100) / 100).toFixed(2);
        const round2 = n => Math.round((Number(n) || 0) * 100) / 100;
        const esc = s => String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));

        function timeAgo(ms) {
            const diff = Date.now() - Number(ms);
            if (!ms || diff < 0) return '';
            const m = Math.floor(diff / 60000);
            if (m < 1) return 'just now';
            if (m < 60) return m + ' min ago';
            const h = Math.floor(m / 60);
            if (h < 24) return h + (h === 1 ? ' hour ago' : ' hours ago');
            const d = Math.floor(h / 24);
            if (d < 30) return d + (d === 1 ? ' day ago' : ' days ago');
            const mo = Math.floor(d / 30);
            return mo + (mo === 1 ? ' month ago' : ' months ago');
        }
        function refreshAgo() {
            document.querySelectorAll('[data-ago]').forEach(el => { el.textContent = timeAgo(el.dataset.ago); });
        }
        function formatDate(ms) {
            const d = new Date(Number(ms));
            if (isNaN(d.getTime())) return '';
            return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        }

        // ---- Category cards ------------------------------------------------------------
        const cards = Array.from(document.querySelectorAll('.fare-card'));

        function readCard(card) {
            const values = {};
            card.querySelectorAll('input[data-field]').forEach(input => { values[input.dataset.field] = round2(input.value); });
            return values;
        }
        function isDirty(card) {
            const cat = card.dataset.category;
            const current = readCard(card);
            return Object.keys(FIELD_LABELS).some(f => round2(saved[cat][f]) !== current[f]);
        }
        function updateCard(card) {
            const cat = card.dataset.category;
            const values = readCard(card);
            const km = Number(previewKm.value) || 0;
            const total = values.baseFare + km * values.perKm + values.bookingFee;
            card.querySelector('[data-example-km]').textContent = km;
            card.querySelector('[data-example-total]').textContent = total.toFixed(2);

            const dirty = isDirty(card);
            card.classList.toggle('dirty', dirty);
            card.querySelector('[data-save]').disabled = !dirty;
            card.querySelector('[data-reset]').disabled = !dirty;
            card.querySelectorAll('input[data-field]').forEach(input => {
                input.classList.toggle('changed', round2(input.value) !== round2(saved[cat][input.dataset.field]));
            });
            const status = card.querySelector('[data-status]');
            if (dirty) {
                status.textContent = 'Unsaved changes';
                status.className = 'fare-status dirty';
            } else if (status.classList.contains('dirty')) {
                status.textContent = '';
                status.className = 'fare-status';
            }
        }
        function fillCard(card) {
            const cat = card.dataset.category;
            card.querySelectorAll('input[data-field]').forEach(input => {
                input.value = round2(saved[cat][input.dataset.field]).toFixed(2);
            });
            card.querySelectorAll('[data-saved-hint]').forEach(el => {
                el.textContent = money(saved[cat][el.dataset.savedHint]);
            });
            updateCard(card);
        }
        function setStatus(card, text, kind) {
            const status = card.querySelector('[data-status]');
            status.textContent = text;
            status.className = 'fare-status ' + (kind || '');
        }

        cards.forEach(card => {
            card.querySelectorAll('input[data-field]').forEach(input => input.addEventListener('input', () => updateCard(card)));
            card.querySelector('[data-reset]').addEventListener('click', () => { fillCard(card); setStatus(card, '', ''); });
            card.querySelector('[data-save]').addEventListener('click', () => saveCard(card));
            card.querySelectorAll('input[data-field]').forEach(input => input.addEventListener('keydown', e => {
                if (e.key === 'Enter') { e.preventDefault(); if (isDirty(card)) saveCard(card); }
            }));
            updateCard(card);
        });

        function saveCard(card) {
            const cat = card.dataset.category;
            const values = readCard(card);
            for (const f of Object.keys(FIELD_LABELS)) {
                if (!(values[f] >= 0)) { setStatus(card, FIELD_LABELS[f] + ' must be 0 or more', 'err'); return; }
            }
            const btn = card.querySelector('[data-save]');
            btn.disabled = true;
            setStatus(card, 'Saving…', '');

            fetch('save-fare-settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ category: cat, ...values })
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    setStatus(card, data.message || 'Could not save', 'err');
                    btn.disabled = false;
                    return;
                }
                saved[cat] = Object.assign({}, saved[cat], data.settings || values);
                fillCard(card);
                const meta = card.querySelector('[data-meta]');
                meta.innerHTML = '<i class="fas fa-clock"></i> Updated ' + esc(formatDate(data.settings?.updatedAt || Date.now())) +
                    (data.settings?.updatedBy ? ' by ' + esc(data.settings.updatedBy) : '');
                if (data.unchanged) {
                    setStatus(card, 'Nothing changed, values already saved', '');
                } else {
                    setStatus(card, 'Saved. The app uses the new prices from the next booking.', 'ok');
                    if (data.history) addHistoryRow(data.history, true);
                }
            })
            .catch(() => { setStatus(card, 'Network error, please try again', 'err'); btn.disabled = false; });
        }

        // ---- Preview distance --------------------------------------------------------------
        function syncKm(from) {
            let km = Number(from.value);
            if (!(km > 0)) km = 1;
            if (from === previewKmRange) previewKm.value = km; else previewKmRange.value = Math.min(50, Math.max(1, Math.round(km)));
            cards.forEach(updateCard);
        }
        previewKm.addEventListener('input', () => syncKm(previewKm));
        previewKmRange.addEventListener('input', () => syncKm(previewKmRange));

        // ---- History -----------------------------------------------------------------------
        function chipHtml(field, change) {
            const from = round2(change.from), to = round2(change.to), delta = to - from;
            return '<span class="chip">' +
                '<span class="lbl">' + esc(FIELD_LABELS[field] || field) + '</span>' +
                '<span class="from">' + money(from) + '</span>' +
                '<i class="fas fa-arrow-right"></i>' +
                '<span class="to">' + money(to) + '</span>' +
                '<span class="delta ' + (delta >= 0 ? 'up' : 'down') + '">' + (delta >= 0 ? '+' : '-') + Math.abs(delta).toFixed(2) + '</span>' +
                '</span>';
        }
        function addHistoryRow(entry, highlight) {
            const lbl = CATEGORY_LABELS[entry.category] || { letter: '?', name: entry.category };
            const tr = document.createElement('tr');
            tr.dataset.category = entry.category;
            if (entry.id) tr.dataset.id = entry.id;
            if (highlight) tr.className = 'new-entry';
            tr.innerHTML =
                '<td class="when"><strong>' + esc(formatDate(entry.changedAt)) + '</strong><span data-ago="' + Number(entry.changedAt) + '"></span></td>' +
                '<td><span class="cat-pill ' + (entry.category === 'categoryB' ? 'b' : '') + '">' + esc(lbl.letter) + ' &middot; ' + esc(lbl.name) + '</span></td>' +
                '<td class="who">' + esc(entry.changedBy || 'admin') + '</td>' +
                '<td><div class="chips">' + Object.keys(entry.changes || {}).map(f => chipHtml(f, entry.changes[f])).join('') + '</div></td>';
            historyBody.insertBefore(tr, historyBody.firstChild);
            historyEmpty.hidden = true;
            historyCount.textContent = historyBody.querySelectorAll('tr').length;
            const opt = historyFilter.querySelector('option[value="' + entry.category + '"]');
            if (opt) {
                const n = (opt.textContent.match(/\((\d+)\)/) || [0, 0])[1];
                opt.textContent = lbl.name + ' (' + (Number(n) + 1) + ')';
            }
            const last = document.getElementById('lastChange');
            last.innerHTML = '<i class="fas fa-history"></i><div><strong>Last change: ' + esc(lbl.name) + '</strong><span>' +
                esc(formatDate(entry.changedAt)) + ' &middot; ' + esc(entry.changedBy || 'admin') + '</span></div>';
            applyHistoryFilter();
            refreshAgo();
        }
        function applyHistoryFilter() {
            const v = historyFilter.value;
            let shown = 0;
            historyBody.querySelectorAll('tr').forEach(tr => {
                const ok = !v || tr.dataset.category === v;
                tr.style.display = ok ? '' : 'none';
                if (ok) shown++;
            });
            historyEmpty.hidden = shown > 0;
        }
        historyFilter.addEventListener('change', applyHistoryFilter);

        refreshAgo();
        setInterval(refreshAgo, 60000);
    </script>
</body>
</html>
