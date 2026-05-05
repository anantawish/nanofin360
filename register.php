<?php
session_start();

$db_file = 'nanofin_users.sqlite';
try {
    $db = new PDO('sqlite:' . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, 
        fullname TEXT, 
        email TEXT, 
        business_name TEXT, 
        registered_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

$message = "";
$is_registered = isset($_SESSION['is_registered']) && $_SESSION['is_registered'] === true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_action'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $business = trim($_POST['business_name'] ?? '');

    if (!empty($fullname) && !empty($email)) {
        $stmt = $db->prepare("INSERT INTO users (fullname, email, business_name) VALUES (:fname, :email, :bname)");
        $stmt->execute([
            ':fname' => $fullname,
            ':email' => $email,
            ':bname' => $business
        ]);
        
        $_SESSION['is_registered'] = true;
        $is_registered = true;
        $message = "Registration successful. You can download the software now.";
    } else {
        $message = "Please complete all required fields.";
    }
}

if (isset($_GET['reset'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>nanofin360 - Finance and Leasing Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f6f9;
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            color: #333;
        }
        .wrapper {
            display: flex;
            background-color: #ffffff;
            width: 100%;
            max-width: 1050px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            overflow: hidden;
            border: 1px solid #e0e0e0;
            margin: 20px;
        }
        /* Left Panel: Features */
        .info-panel {
            background-color: #fafbfc;
            padding: 50px 45px;
            width: 55%;
            border-right: 1px solid #eaeaea;
        }
        .info-panel h2 {
            font-size: 24px;
            color: #202124;
            margin-bottom: 25px;
            font-weight: 700;
            line-height: 1.3;
        }
        .feature-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .feature-list li {
            position: relative;
            padding-left: 28px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.6;
            color: #5f6368;
        }
        .feature-list li::before {
            content: '✓';
            position: absolute;
            left: 0;
            top: 0;
            color: #0F9D58;
            font-weight: bold;
            font-size: 16px;
        }
        .feature-list li strong {
            color: #3c4043;
            display: block;
            font-size: 15px;
            margin-bottom: 4px;
        }

        /* Right Panel: Form */
        .form-panel {
            padding: 50px 45px;
            width: 45%;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .logo-container { margin-bottom: 35px; font-family: 'Poppins', sans-serif; text-align: center; }
        .nanofin360 { font-size: 46px; font-weight: 700; letter-spacing: -1.5px; margin: 0; line-height: 1; }
        .color-1 { color: #4285F4; } .color-2 { color: #DB4437; } .color-3 { color: #F4B400; } .color-4 { color: #0F9D58; }
        .tagline { font-size: 13px; color: #757575; margin-top: 6px; letter-spacing: 0.5px; text-transform: uppercase; }

        .form-group { margin-bottom: 20px; text-align: left; }
        .form-group label { display: block; font-size: 13px; margin-bottom: 8px; color: #5f6368; font-weight: 600;}
        .form-control {
            width: 100%; padding: 12px 15px; font-size: 14px;
            border: 1px solid #dadce0; border-radius: 6px; box-sizing: border-box;
            transition: border-color 0.2s; font-family: 'Inter', sans-serif;
        }
        .form-control:focus { border-color: #4285F4; outline: 0; box-shadow: 0 0 0 3px rgba(66,133,244,0.15); }

        .btn {
            display: inline-block; width: 100%; padding: 14px; font-size: 15px; font-weight: 600;
            color: #fff; background-color: #4285F4; border: none; border-radius: 6px;
            cursor: pointer; transition: background-color 0.2s; font-family: 'Poppins', sans-serif;
            text-align: center; text-decoration: none; box-sizing: border-box;
        }
        .btn:hover { background-color: #3367d6; }
        .btn-success { background-color: #0F9D58; margin-top: 15px; }
        .btn-success:hover { background-color: #0b7a44; }
        .btn-link { background: none; color: #757575; font-size: 13px; text-decoration: underline; margin-top: 15px; font-weight: normal; border: none; cursor: pointer; padding: 0;}
        .btn-link:hover { color: #333; }

        .alert { padding: 12px; border-radius: 6px; margin-bottom: 25px; font-size: 14px; text-align: center;}
        .alert-success { background-color: #e6f4ea; color: #137333; border: 1px solid #ceead6;}
        .alert-danger { background-color: #fce8e6; color: #c5221f; border: 1px solid #f8d4d2;}

        .disclaimer {
            margin-top: 35px;
            font-size: 11px;
            color: #9aa0a6;
            text-align: center;
            line-height: 1.6;
            border-top: 1px solid #f1f3f4;
            padding-top: 15px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .wrapper { flex-direction: column; margin: 15px; }
            .info-panel, .form-panel { width: 100%; box-sizing: border-box; padding: 40px 25px; }
            .info-panel { border-right: none; border-bottom: 1px solid #eaeaea; }
        }
    </style>
</head>
<body>

    <div class="wrapper">
        <!-- Left Panel: Features -->
        <div class="info-panel">
            <h2>Upgrade your lending business with an end-to-end technology platform</h2>
            <ul class="feature-list">
                <li>
                    <strong>360 Customer Data and KYC</strong>
                    Collect and verify customer identities to gain a complete customer view.
                </li>
                <li>
                    <strong>Credit Scoring and Affordability (DTI)</strong>
                    Assess risk and repayment capacity systematically to reduce over-lending.
                </li>
                <li>
                    <strong>Collections and DPD Tracking</strong>
                    Plan debt follow-up with accurate and timely delinquency-day monitoring (DPD).
                </li>
                <li>
                    <strong>NPL and Portfolio Management</strong>
                    Manage non-performing loans with structure and track portfolio quality by branch.
                </li>
                <li>
                    <strong>Early Warning System (EWS)</strong>
                    Monitor risk proactively and intervene before accounts become NPL.
                </li>
                <li>
                    <strong>Risk Lab and Bayesian Modeling (LEI)</strong>
                    Use local economic index data with advanced models for professional portfolio simulation.
                </li>
            </ul>
        </div>

        <!-- Right Panel: Registration Form -->
        <div class="form-panel">
            <div class="logo-container">
                <h1 class="nanofin360">
                    <span class="color-1">n</span><span class="color-2">a</span><span class="color-3">n</span><span class="color-4">o</span><span class="color-1">f</span><span class="color-2">i</span><span class="color-3">n</span><span class="color-4">3</span><span class="color-1">6</span><span class="color-2">0</span>
                </h1>
                <p class="tagline">Free finance and leasing software</p>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert <?= $is_registered ? 'alert-success' : 'alert-danger' ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if (!$is_registered): ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="fullname" class="form-control" required placeholder="e.g. John Doe">
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" class="form-control" required placeholder="e.g. john@example.com">
                    </div>
                    <div class="form-group">
                        <label>Business Name</label>
                        <input type="text" name="business_name" class="form-control" placeholder="e.g. Smart Leasing Co., Ltd.">
                    </div>
                    <button type="submit" name="register_action" class="btn">Register for Access</button>
                </form>
            <?php else: ?>
                <div style="text-align: center; margin-bottom: 25px;">
                    <h3 style="color: #202124; margin-top: 0; margin-bottom: 10px;">Welcome Back</h3>
                    <p style="color: #5f6368; font-size: 14px; line-height: 1.5; margin: 0;">Verification complete. You can download the software package now.</p>
                </div>
                
                <a href="nanofin360_v1.zip" class="btn btn-success" download>Download .ZIP</a>
                
                <form method="GET" action="" style="text-align: center;">
                    <button type="submit" name="reset" class="btn btn-link">Register a New Account</button>
                </form>
            <?php endif; ?>

            <!-- Disclaimer -->
            <div class="disclaimer">
                <strong>Disclaimer:</strong> This software is provided free of charge on an "as-is" basis. By downloading, using, modifying, or deploying it in your business, you accept all risks. The developer is not liable for damages, data loss, or any financial impact from system usage.
            </div>

        </div>
    </div>

</body>
</html>
