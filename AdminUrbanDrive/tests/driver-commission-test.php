<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Commission Test - PerdaRide</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .driver-input { margin-bottom: 20px; }
        .driver-input input { padding: 10px; width: 300px; border: 1px solid #ddd; border-radius: 5px; }
        .driver-input button { padding: 10px 20px; background: #ff6b35; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px; }
        .commission-card { background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0; }
        .commission-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin: 20px 0; }
        .summary-item { text-align: center; padding: 15px; background: white; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .summary-value { font-size: 24px; font-weight: bold; color: #333; }
        .summary-label { color: #666; font-size: 14px; }
        .jobs-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .jobs-table th, .jobs-table td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        .jobs-table th { background: #f8f9fa; font-weight: 600; }
        .status-paid { color: #28a745; font-weight: bold; }
        .status-unpaid { color: #ffc107; font-weight: bold; }
        .error { color: #dc3545; background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .success { color: #155724; background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Driver Commission Test</h1>
        <p>Test how drivers can check their commission information.</p>
        
        <div class="driver-input">
            <input type="text" id="driverId" placeholder="Enter Driver ID (e.g., hsnA4Zk1SEYyE1QsdAHEhjchabJ2)" value="hsnA4Zk1SEYyE1QsdAHEhjchabJ2">
            <button onclick="loadDriverCommission()">Load Commission Data</button>
        </div>
        
        <div id="loading" style="display: none;">
            <p>Loading commission data...</p>
        </div>
        
        <div id="error" class="error" style="display: none;"></div>
        
        <div id="commissionData" style="display: none;">
            <div class="commission-card">
                <h2>Commission Summary</h2>
                <div class="commission-summary">
                    <div class="summary-item">
                        <div class="summary-value" id="totalEarnings">RM 0.00</div>
                        <div class="summary-label">Total Earnings</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-value" id="totalCommission">RM 0.00</div>
                        <div class="summary-label">Total Commission</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-value" id="paidCommission">RM 0.00</div>
                        <div class="summary-label">Paid Commission</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-value" id="unpaidCommission">RM 0.00</div>
                        <div class="summary-label">Unpaid Commission</div>
                    </div>
                </div>
            </div>
            
            <div class="commission-card">
                <h3>Recent Jobs</h3>
                <table class="jobs-table" id="jobsTable">
                    <thead>
                        <tr>
                            <th>Booking ID</th>
                            <th>Fare Amount</th>
                            <th>Commission</th>
                            <th>Status</th>
                            <th>Job Date</th>
                        </tr>
                    </thead>
                    <tbody id="jobsBody">
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function loadDriverCommission() {
            const driverId = document.getElementById('driverId').value.trim();
            if (!driverId) {
                showError('Please enter a Driver ID');
                return;
            }
            
            document.getElementById('loading').style.display = 'block';
            document.getElementById('error').style.display = 'none';
            document.getElementById('commissionData').style.display = 'none';
            
            fetch(`driver-commission-api.php?driver_id=${encodeURIComponent(driverId)}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('loading').style.display = 'none';
                    
                    if (data.success) {
                        displayCommissionData(data.data);
                    } else {
                        showError(data.message || 'Error loading commission data');
                    }
                })
                .catch(error => {
                    document.getElementById('loading').style.display = 'none';
                    showError('Network error: ' + error.message);
                });
        }
        
        function displayCommissionData(data) {
            const summary = data.commission_summary;
            
            document.getElementById('totalEarnings').textContent = 'RM ' + summary.total_earnings.toFixed(2);
            document.getElementById('totalCommission').textContent = 'RM ' + summary.total_commission.toFixed(2);
            document.getElementById('paidCommission').textContent = 'RM ' + summary.paid_commission.toFixed(2);
            document.getElementById('unpaidCommission').textContent = 'RM ' + summary.unpaid_commission.toFixed(2);
            
            const jobsBody = document.getElementById('jobsBody');
            jobsBody.innerHTML = '';
            
            if (data.recent_jobs && Object.keys(data.recent_jobs).length > 0) {
                Object.values(data.recent_jobs).forEach(job => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${job.booking_id}</td>
                        <td>RM ${job.fare_amount.toFixed(2)}</td>
                        <td>RM ${job.commission_amount.toFixed(2)}</td>
                        <td class="status-${job.status}">${job.status.toUpperCase()}</td>
                        <td>${job.job_date}</td>
                    `;
                    jobsBody.appendChild(row);
                });
            } else {
                jobsBody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: #666;">No jobs found</td></tr>';
            }
            
            document.getElementById('commissionData').style.display = 'block';
        }
        
        function showError(message) {
            document.getElementById('error').textContent = message;
            document.getElementById('error').style.display = 'block';
        }
        
        // Load commission data on page load for the default driver ID
        window.onload = function() {
            loadDriverCommission();
        };
    </script>
</body>
</html>
