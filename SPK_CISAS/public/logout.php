<?php

declare(strict_types=1);

/**
 * Proses Logout
 * Mengakhiri sesi pengguna melalui request POST yang valid.
 */

require_once dirname(__DIR__) . '/app/helpers/auth.php';

auth_initialize();

if (auth_guest()) {
    flash_info('Sesi pengguna sudah berakhir. Silakan masuk kembali.');
    redirect('login.php');
}

if (!is_post()) {
    flash_warning('Permintaan logout tidak valid. Gunakan tombol Keluar dari Sistem.');
    redirect('dashboard.php');
}

if (!csrf_validate_request('logout')) {
    flash_error('Token keamanan logout tidak valid atau telah kedaluwarsa.');
    redirect('dashboard.php');
}

$loggedOutUserName = auth_name() ?? 'Pengguna';
auth_logout();
flash_success('Anda telah keluar dari sistem. Sampai jumpa, ' . $loggedOutUserName . '.');
redirect('login.php');
