<?php
// Server-side login errors (auth.php redirects back here with ?error=...).
$loginErrors = [
    'invalid_credentials' => 'Email or password is incorrect.',
    'not_admin' => 'This account is not on the admin list. Ask an existing admin to add it under /admins in the Firebase database.',
    'session_expired' => 'Your session expired. Please log in again.',
    'not_configured' => 'Firebase is not configured on the server.',
];
$loginError = isset($_GET['error']) && isset($loginErrors[$_GET['error']]) ? $loginErrors[$_GET['error']] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Urban Drive</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            height: 100vh;
            display: flex;
            overflow: hidden;
        }

        .login-wrapper {
            display: flex;
            width: 100%;
            height: 100%;
        }

        /* Left Side - Branding */
        .branding-section {
            flex: 1;
            background: linear-gradient(135deg, #fff5f2 0%, #ffe5e0 50%, #fff5f2 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px;
            position: relative;
            overflow: hidden;
        }

        .branding-section::before {
            content: '';
            position: absolute;
            width: 200%;
            height: 200%;
            background: 
                repeating-linear-gradient(
                    45deg,
                    transparent,
                    transparent 10px,
                    rgba(255, 107, 53, 0.03) 10px,
                    rgba(255, 107, 53, 0.03) 20px
                );
            animation: slide 20s linear infinite;
        }

        @keyframes slide {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }

        .logo-container {
            position: relative;
            z-index: 1;
            text-align: center;
            max-width: 400px;
        }

        .logo-illustration {
            width: 280px;
            height: 200px;
            margin: 0 auto 40px;
            position: relative;
        }

        /* Car SVG */
        .car-svg {
            width: 100%;
            height: 100%;
        }

        .logo-text {
            font-size: 48px;
            font-weight: 700;
            color: #ff6b35;
            letter-spacing: -1px;
            margin-bottom: 20px;
            font-family: 'Georgia', serif;
        }

        .branding-description {
            font-size: 18px;
            color: #64748b;
            line-height: 1.6;
            max-width: 400px;
            margin: 0 auto;
        }

        /* Right Side - Login Form */
        .login-section {
            flex: 1;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
            position: relative;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            padding: 50px 40px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .login-header {
            margin-bottom: 40px;
        }

        .login-header h1 {
            color: #1e293b;
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .login-header p {
            color: #64748b;
            font-size: 15px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #1e293b;
            font-weight: 600;
            font-size: 14px;
        }

        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.2s ease;
            background: #ffffff;
            color: #1e293b;
        }

        .form-group input:focus {
            outline: none;
            border-color: #ff6b35;
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
        }

        .form-group input::placeholder {
            color: #94a3b8;
        }

        .forgot-password {
            text-align: right;
            margin-bottom: 32px;
        }

        .forgot-password a {
            color: #ff6b35;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .forgot-password a:hover {
            color: #e55a2b;
            text-decoration: underline;
        }

        .login-btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #ff6b35 0%, #ff8c5a 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3);
        }

        .login-btn:hover {
            background: linear-gradient(135deg, #e55a2b 0%, #ff6b35 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .error-message {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: none;
            border: 1px solid #fca5a5;
            font-size: 14px;
        }

        /* Responsive */
        @media (max-width: 968px) {
            .login-wrapper {
                flex-direction: column;
            }

            .branding-section {
                flex: 0 0 40%;
                padding: 40px 30px;
            }

            .logo-illustration {
                width: 200px;
                height: 140px;
                margin-bottom: 20px;
            }

            .logo-text {
                font-size: 36px;
            }

            .branding-description {
                font-size: 14px;
            }

            .login-section {
                flex: 1;
                padding: 30px 20px;
            }
        }

        @media (max-width: 640px) {
            .branding-section {
                display: none;
            }

            .login-section {
                padding: 20px;
            }

            .login-card {
                padding: 40px 30px;
            }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <!-- Left Side - Branding -->
        <div class="branding-section">
            <div class="logo-container">
                <div class="logo-illustration">
                    <svg class="car-svg" viewBox="0 0 280 200" xmlns="http://www.w3.org/2000/svg">
                        <!-- Winding Road -->
                        <path d="M 20 160 Q 60 140, 100 150 Q 140 160, 180 145 Q 220 130, 260 140" 
                              stroke="#ff6b35" stroke-width="8" fill="none" stroke-linecap="round"/>
                        
                        <!-- Car Body -->
                        <g transform="translate(120, 120)">
                            <!-- Car main body -->
                            <rect x="0" y="10" width="50" height="25" rx="5" fill="#ff6b35"/>
                            <!-- Car roof -->
                            <path d="M 10 10 Q 15 0, 25 0 Q 35 0, 40 10" stroke="#ff6b35" stroke-width="3" fill="none"/>
                            <!-- Windshield -->
                            <rect x="12" y="12" width="8" height="8" rx="1" fill="#fff5f2" opacity="0.8"/>
                            <!-- Headlights -->
                            <circle cx="5" cy="20" r="3" fill="#fff5f2"/>
                            <circle cx="45" cy="20" r="3" fill="#fff5f2"/>
                            <!-- Wheels -->
                            <circle cx="12" cy="35" r="6" fill="#1e293b"/>
                            <circle cx="38" cy="35" r="6" fill="#1e293b"/>
                        </g>
                        
                        <!-- Tree -->
                        <g transform="translate(200, 130)">
                            <!-- Tree trunk -->
                            <rect x="8" y="20" width="6" height="15" fill="#ff6b35"/>
                            <!-- Tree top -->
                            <circle cx="11" cy="15" r="12" fill="#ff6b35"/>
                        </g>
                    </svg>
                </div>
                <div class="logo-text">Urban Drive</div>
                <p class="branding-description">
                    Your trusted ride-sharing platform. Connecting drivers and passengers seamlessly across the city.
                </p>
            </div>
        </div>

        <!-- Right Side - Login Form -->
        <div class="login-section">
            <div class="login-card">
                <div class="login-header">
                    <h1>Login</h1>
                    <p>Welcome back! Please login to your account.</p>
                </div>
                
                <div class="error-message" id="errorMessage"<?php if ($loginError !== '') echo ' style="display:block"'; ?>><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div>
                
                <form id="loginForm" method="POST" action="auth.php">
                    <div class="form-group">
                        <label for="username">Email</label>
                        <input type="email" id="username" name="username" placeholder="Enter your admin email" autocomplete="username" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                    </div>
                    
                    <div class="forgot-password">
                        <a href="#">Forgot password?</a>
                    </div>
                    
                    <button type="submit" class="login-btn">LOGIN</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            
            if (!username || !password) {
                showError('Please fill in all fields');
                return;
            }
            
            // Submit form
            this.submit();
        });

        function showError(message) {
            const errorDiv = document.getElementById('errorMessage');
            errorDiv.textContent = message;
            errorDiv.style.display = 'block';
        }
    </script>
</body>
</html>
