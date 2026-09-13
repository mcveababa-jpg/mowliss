<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'success' => false,
        'message' => 'Please submit the registration form.'
    ], 405);
}

try {
    $pdo = get_pdo();
} catch (RuntimeException $e) {
    json_response([
        'success' => false,
        'message' => 'Database unavailable. Please try again later.'
    ], 500);
}

/*
    Safely read POST values.
    This also protects against accidental repeated/duplicate field names.
*/
function postString(string $key): string
{
    $value = $_POST[$key] ?? '';

    if (is_array($value)) {
        $value = end($value);

        if ($value === false) {
            $value = '';
        }
    }

    return trim((string)$value);
}

function postNullable(string $key): ?string
{
    $value = postString($key);

    return $value === '' ? null : $value;
}

function validDate(string $date): bool
{
    $dateTime = DateTime::createFromFormat('Y-m-d', $date);

    return $dateTime !== false && $dateTime->format('Y-m-d') === $date;
}

$status = postString('user_status');
$password = postString('password');
$confirmPassword = postString('confirm_password');
$termsAgreed = !empty($_POST['terms_agreed']);

$phone = postNullable('phone_number');
$dateOfBirth = postNullable('date_of_birth');

$errors = [];

$allowedStatuses = ['student', 'staff', 'foreman', 'admin'];

if (!in_array($status, $allowedStatuses, true)) {
    $errors[] = 'Please select a valid user status.';
}

if ($password === '') {
    $errors[] = 'Password is required.';
}

if ($confirmPassword === '') {
    $errors[] = 'Confirm Password is required.';
}

if ($password !== $confirmPassword) {
    $errors[] = 'Passwords do not match.';
}

if (strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters long.';
}

if (!$termsAgreed) {
    $errors[] = 'You must agree to the Terms & Conditions and MoWLiSS security policy.';
}

if ($dateOfBirth !== null && !validDate($dateOfBirth)) {
    $errors[] = 'Date of Birth is invalid.';
}

/*
    Validate status-specific fields.
*/
if ($status === 'student') {
    $firstName = postString('first_name');
    $lastName = postString('last_name');
    $studentId = postString('student_id');
    $program = postString('program');
    $department = postString('department');

    if ($firstName === '') {
        $errors[] = 'First Name is required.';
    }

    if ($lastName === '') {
        $errors[] = 'Last Name is required.';
    }

    if ($studentId === '') {
        $errors[] = 'Student ID No. is required.';
    }

    if ($program === '') {
        $errors[] = 'Program is required.';
    }

    if ($department === '') {
        $errors[] = 'Department is required.';
    }
}

if ($status === 'staff') {
    $fullName = postString('full_name');
    $workerRegNo = postString('worker_reg_no');
    $department = postString('department');
    $compound = postNullable('compound');

    if ($fullName === '') {
        $errors[] = 'Full Name is required.';
    }

    if ($workerRegNo === '') {
        $errors[] = 'Worker Reg No. is required.';
    }

    if ($department === '') {
        $errors[] = 'Department is required.';
    }
}

if ($status === 'foreman') {
    $firstName = postString('first_name');
    $lastName = postString('last_name');
    $foremanRegNo = postString('foreman_reg_no');
    $department = postString('department');

    if ($firstName === '') {
        $errors[] = 'First Name is required.';
    }

    if ($lastName === '') {
        $errors[] = 'Last Name is required.';
    }

    if ($foremanRegNo === '') {
        $errors[] = 'Foreman Reg No. is required.';
    }

    if ($department === '') {
        $errors[] = 'Department is required.';
    }
}

if ($status === 'admin') {
    $adminId = postString('admin_id');
    $fullName = postNullable('full_name');

    if ($adminId === '') {
        $errors[] = 'Admin ID is required.';
    }

    $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();

    if ($adminCount > 0) {
        $errors[] = 'Admin registration is disabled because an admin account already exists.';
    }
}

if (!empty($errors)) {
    json_response([
        'success' => false,
        'message' => implode(' ', $errors)
    ], 422);
}

try {
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($status === 'student') {
        $stmt = $pdo->prepare(
            "INSERT INTO students
                (
                    student_id,
                    first_name,
                    last_name,
                    program,
                    department,
                    phone_number,
                    date_of_birth,
                   password_hash,
                   terms_agreed,
                   is_approved,
                   account_status,
                   is_online,
                   device_status
               )
             VALUES
               (
                   :student_id,
                   :first_name,
                   :last_name,
                   :program,
                   :department,
                   :phone_number,
                   :date_of_birth,
                   :password_hash,
                   1,
                   0,
                   'pending',
                   0,
                   'healthy'
               )"
        );

        $stmt->execute([
            'student_id' => postString('student_id'),
            'first_name' => postString('first_name'),
            'last_name' => postString('last_name'),
            'program' => postString('program'),
            'department' => postString('department'),
            'phone_number' => $phone,
            'date_of_birth' => $dateOfBirth,
            'password_hash' => $passwordHash,
        ]);
    }

    if ($status === 'staff') {
        $stmt = $pdo->prepare(
            "INSERT INTO staff
                (
                    worker_reg_no,
                    full_name,
                    department,
                    compound,
                    phone_number,
                    date_of_birth,
                   password_hash,
                   terms_agreed,
                   is_approved,
                   account_status,
                   is_online,
                   device_status
               )
             VALUES
               (
                   :worker_reg_no,
                   :full_name,
                   :department,
                   :compound,
                   :phone_number,
                   :date_of_birth,
                   :password_hash,
                   1,
                   0,
                   'pending',
                   0,
                   'healthy'
               )"
        );

        $stmt->execute([
            'worker_reg_no' => postString('worker_reg_no'),
            'full_name' => postString('full_name'),
            'department' => postString('department'),
            'compound' => postNullable('compound'),
            'phone_number' => $phone,
            'date_of_birth' => $dateOfBirth,
            'password_hash' => $passwordHash,
        ]);
    }

    if ($status === 'foreman') {
        $stmt = $pdo->prepare(
            "INSERT INTO foremen
                (
                    foreman_reg_no,
                    first_name,
                    last_name,
                    department,
                    phone_number,
                    date_of_birth,
                   password_hash,
                   terms_agreed,
                   is_approved,
                   account_status,
                   is_online,
                   device_status
               )
             VALUES
               (
                   :foreman_reg_no,
                   :first_name,
                   :last_name,
                   :department,
                   :phone_number,
                   :date_of_birth,
                   :password_hash,
                   1,
                   0,
                   'pending',
                   0,
                   'healthy'
               )"
        );

        $stmt->execute([
            'foreman_reg_no' => postString('foreman_reg_no'),
            'first_name' => postString('first_name'),
            'last_name' => postString('last_name'),
            'department' => postString('department'),
            'phone_number' => $phone,
            'date_of_birth' => $dateOfBirth,
            'password_hash' => $passwordHash,
        ]);
    }

    if ($status === 'admin') {
        $stmt = $pdo->prepare(
            "INSERT INTO admins
                (
                    admin_id,
                    password_hash,
                    full_name,
                    terms_agreed
                )
             VALUES
                (
                    :admin_id,
                    :password_hash,
                    :full_name,
                    1
                )"
        );

        $stmt->execute([
            'admin_id' => postString('admin_id'),
            'password_hash' => $passwordHash,
            'full_name' => postNullable('full_name'),
        ]);
    }

    json_response([
        'success' => true,
        'message' => 'Account created successfully. Please login.'
    ]);
} catch (PDOException $e) {
    error_log('MoWLiSS Registration Error: ' . $e->getMessage());

    if ($e->getCode() === '23000') {
        json_response([
            'success' => false,
            'message' => 'The selected ID/registration number already exists.'
        ], 409);
    }

    json_response([
        'success' => false,
        'message' => 'Database error occurred while creating the account.'
    ], 500);
}