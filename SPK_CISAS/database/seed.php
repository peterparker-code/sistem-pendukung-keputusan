<?php

declare(strict_types=1);

/*
 * Data Awal Sistem SPK PT CISAS
 * File ini memasukkan:
 * 1. Tiga akun pengguna:
 * - Administrator
 * - Supervisor Maintenance
 * - Teknisi Maintenance
 * 2. Enam alternatif motor:
 * - A1 sampai A6
 * 3. Lima kriteria:
 * - C1 sampai C5
 * 4. Dua belas item checklist:
 * - Enam item mekanikal
 * - Enam item elektrikal
 * Data nameplate seed diselaraskan dengan data master yang digunakan pada database penelitian aktif.
 */

// Muat Koneksi Database

$pdo = require dirname(__DIR__)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'config'
    . DIRECTORY_SEPARATOR
    . 'database.php';

// Periksa Tabel yang Diperlukan

$requiredTables = [
    'users',
    'machines',
    'criteria',
    'assessment_periods',
    'assessments',
    'measurements',
    'checklist_items',
    'checklist_results',
    'assessment_scores',
    'ahp_comparisons',
    'ahp_results',
    'ahp_summaries',
    'topsis_results',
    'maintenance_actions',
    'audit_logs',
    'calculation_runs',
    'login_attempts',
];

$tableCheckStatement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM sqlite_master
     WHERE type = 'table'
       AND name = :table_name"
);

foreach ($requiredTables as $requiredTable) {
    $tableCheckStatement->execute([
        'table_name' => $requiredTable,
    ]);

    $tableExists =
        (int) $tableCheckStatement->fetchColumn() === 1;

    if (!$tableExists) {
        throw new RuntimeException(
            "Tabel {$requiredTable} belum tersedia. "
            . 'Pastikan database/schema.sql sudah dijalankan.'
        );
    }
}

/*
 * Data Pengguna Awal
 * Password hanya digunakan untuk database baru.
 * Jika seed.php dijalankan ulang pada database yang sudah digunakan,
 * password pengguna yang telah diubah tidak akan dikembalikan ke
 * password awal.
 */

$users = [
    [
        'name' => 'Administrator Sistem',
        'username' => 'admin',
        'password' => 'admin123',
        'role' => 'admin',
    ],

    [
        'name' => 'Supervisor Maintenance',
        'username' => 'supervisor',
        'password' => 'supervisor123',
        'role' => 'supervisor',
    ],

    [
        'name' => 'Teknisi Maintenance',
        'username' => 'teknisi',
        'password' => 'teknisi123',
        'role' => 'technician',
    ],
];

/*
 * Data Enam Alternatif Motor
 * Identitas alternatif:
 * A1 = Mesin Mixer 1 - Scraper
 * A2 = Mesin Mixer 1 - Homogenizer
 * A3 = Mesin Mixer 2 - Scraper
 * A4 = Mesin Mixer 2 - Homogenizer
 * A5 = Mesin Mixer 3 - Scraper
 * A6 = Mesin Mixer 3 - Homogenizer
 * Data yang belum terkonfirmasi dibuat NULL.
 */

$machines = [
    [
        'alternative_code' => 'A1',
        'machine_code' => 'MIX-01',
        'machine_number' => 1,
        'motor_function' => 'scraper',
        'motor_name' => 'Motor Scraper Mesin Mixer 1',
        'brand' => 'Elektrim',
        'voltage' => '380 VAC',
        'rated_current' => 8.7,
        'rated_frequency' => 50,
        'rated_rpm' => 1435,
        'power_hp' => 5.5,
        'number_of_poles' => 4,
        'location' => 'Mesin Mixer 1',
        'status' => 'active',
        'notes' =>
            'Frekuensi nominal 50 Hz dan jumlah kutub 4 telah diverifikasi dari nameplate motor.',
    ],

    [
        'alternative_code' => 'A2',
        'machine_code' => 'MIX-01',
        'machine_number' => 1,
        'motor_function' => 'homogenizer',
        'motor_name' => 'Motor Homogenizer Mesin Mixer 1',
        'brand' => 'Elektrim',
        'voltage' => '220/380 VAC',
        'rated_current' => 7.9,
        'rated_frequency' => 50,
        'rated_rpm' => 2885,
        'power_hp' => 5.5,
        'number_of_poles' => 2,
        'location' => 'Mesin Mixer 1',
        'status' => 'active',
        'notes' => 'Data nameplate motor homogenizer mengikuti data master penelitian aktif.',
    ],

    [
        'alternative_code' => 'A3',
        'machine_code' => 'MIX-02',
        'machine_number' => 2,
        'motor_function' => 'scraper',
        'motor_name' => 'Motor Scraper Mesin Mixer 2',
        'brand' => 'Elektrim',
        'voltage' => '380 VAC',
        'rated_current' => 8.7,
        'rated_frequency' => 50,
        'rated_rpm' => 1435,
        'power_hp' => 5.5,
        'number_of_poles' => 4,
        'location' => 'Mesin Mixer 2',
        'status' => 'active',
        'notes' => 'Data nameplate motor scraper mengikuti data master penelitian aktif.',
    ],

    [
        'alternative_code' => 'A4',
        'machine_code' => 'MIX-02',
        'machine_number' => 2,
        'motor_function' => 'homogenizer',
        'motor_name' => 'Motor Homogenizer Mesin Mixer 2',
        'brand' => 'Elektrim',
        'voltage' => '220/380 VAC',
        'rated_current' => 7.9,
        'rated_frequency' => 50,
        'rated_rpm' => 2885,
        'power_hp' => 5.5,
        'number_of_poles' => 2,
        'location' => 'Mesin Mixer 2',
        'status' => 'active',
        'notes' => 'Data nameplate motor homogenizer mengikuti data master penelitian aktif.',
    ],

    [
        'alternative_code' => 'A5',
        'machine_code' => 'MIX-03',
        'machine_number' => 3,
        'motor_function' => 'scraper',
        'motor_name' => 'Motor Scraper Mesin Mixer 3',
        'brand' => 'Elektrim',
        'voltage' => '380 VAC',
        'rated_current' => 8.7,
        'rated_frequency' => 50,
        'rated_rpm' => 1435,
        'power_hp' => 5.5,
        'number_of_poles' => 4,
        'location' => 'Mesin Mixer 3',
        'status' => 'active',
        'notes' => 'Data nameplate motor scraper mengikuti data master penelitian aktif.',
    ],

    [
        'alternative_code' => 'A6',
        'machine_code' => 'MIX-03',
        'machine_number' => 3,
        'motor_function' => 'homogenizer',
        'motor_name' => 'Motor Homogenizer Mesin Mixer 3',
        'brand' => 'Elektrim',
        'voltage' => '220/380 VAC',
        'rated_current' => 7.9,
        'rated_frequency' => 50,
        'rated_rpm' => 2885,
        'power_hp' => 5.5,
        'number_of_poles' => 2,
        'location' => 'Mesin Mixer 3',
        'status' => 'active',
        'notes' => 'Data nameplate motor homogenizer mengikuti data master penelitian aktif.',
    ],
];

/*
 * Data Lima Kriteria
 * Seluruh kriteria diperlakukan sebagai benefit terhadap urgensi.
 * Artinya:
 * semakin besar nilai kriteria,
 * semakin tinggi kebutuhan pemeliharaan motor.
 */

$criteria = [
    [
        'code' => 'C1',
        'name' => 'Suhu Permukaan Motor',
        'description' =>
            'Rata-rata tiga hasil pengukuran suhu '
            . 'permukaan motor dalam derajat Celsius. '
            . 'Nilai yang lebih tinggi menunjukkan '
            . 'urgensi pemeliharaan yang lebih tinggi.',
        'display_order' => 1,
    ],

    [
        'code' => 'C2',
        'name' => 'Persentase Arus Motor',
        'description' =>
            'Persentase arus aktual rata-rata terhadap '
            . 'arus nominal pada nameplate motor. '
            . 'Nilai yang lebih tinggi menunjukkan '
            . 'urgensi pemeliharaan yang lebih tinggi.',
        'display_order' => 2,
    ],

    [
        'code' => 'C3',
        'name' => 'Deviasi RPM',
        'description' =>
            'Persentase selisih RPM aktual rata-rata '
            . 'terhadap RPM acuan operasional. RPM acuan '
            . 'diperoleh dari RPM nominal yang disesuaikan '
            . 'dengan frekuensi operasi motor.',
        'display_order' => 3,
    ],

    [
        'code' => 'C4',
        'name' => 'Kondisi Mekanikal',
        'description' =>
            'Rata-rata skor hasil pemeriksaan enam '
            . 'indikator kondisi mekanikal motor. '
            . 'Nilai yang lebih tinggi menunjukkan '
            . 'kondisi yang semakin memerlukan pemeliharaan.',
        'display_order' => 4,
    ],

    [
        'code' => 'C5',
        'name' => 'Kondisi Elektrikal',
        'description' =>
            'Rata-rata skor hasil pemeriksaan enam '
            . 'indikator kondisi elektrikal motor. '
            . 'Nilai yang lebih tinggi menunjukkan '
            . 'kondisi yang semakin memerlukan pemeliharaan.',
        'display_order' => 5,
    ],
];

// Checklist Mekanikal C4

$mechanicalItems = [
    [
        'item_code' => 'M1',
        'item_name' => 'Bearing',
        'inspected_part' =>
            'Suara, kelonggaran, pelumasan, '
            . 'dan kondisi rumah bearing.',

        'normal_description' =>
            'Tidak terdapat suara kasar, kelonggaran, '
            . 'kebocoran pelumas, atau kerusakan fisik.',

        'warning_description' =>
            'Terdapat suara ringan, kelonggaran kecil, '
            . 'atau rembesan pelumas yang perlu dipantau.',

        'danger_description' =>
            'Terdapat suara kasar atau ketukan kuat, '
            . 'kelonggaran besar, pelumas keluar '
            . 'berlebihan, atau indikasi bearing '
            . 'macet atau rusak.',

        'allows_na' => 0,
        'display_order' => 1,
    ],

    [
        'item_code' => 'M2',
        'item_name' => 'Coupling dan Poros',
        'inspected_part' =>
            'Kondisi fisik, kekencangan, keselarasan, '
            . 'dan kestabilan putaran.',

        'normal_description' =>
            'Tidak retak, tidak aus, tidak longgar, '
            . 'dan putaran stabil.',

        'warning_description' =>
            'Terdapat keausan ringan, pergeseran kecil, '
            . 'atau indikasi awal ketidakselarasan.',

        'danger_description' =>
            'Retak, longgar, aus berat, tidak sejajar '
            . 'secara jelas, atau putaran tidak stabil.',

        'allows_na' => 0,
        'display_order' => 2,
    ],

    [
        'item_code' => 'M3',
        'item_name' => 'Baut dan Dudukan Motor',
        'inspected_part' =>
            'Baut kaki motor, dudukan, rangka, '
            . 'dan posisi motor.',

        'normal_description' =>
            'Baut dan dudukan kuat, tidak bergeser, '
            . 'retak, atau berkarat berat.',

        'warning_description' =>
            'Terdapat baut mulai longgar, korosi ringan, '
            . 'atau pergeseran kecil.',

        'danger_description' =>
            'Baut lepas atau longgar berat, dudukan '
            . 'bergeser, retak, atau konstruksi '
            . 'tidak kokoh.',

        'allows_na' => 0,
        'display_order' => 3,
    ],

    [
        'item_code' => 'M4',
        'item_name' => 'Gearbox dan Pelumasan',
        'inspected_part' =>
            'Level dan kondisi pelumas, kebocoran, '
            . 'suara, dan kondisi rumah gearbox.',

        'normal_description' =>
            'Level pelumas sesuai, tidak bocor, '
            . 'dan tidak terdapat suara tidak normal.',

        'warning_description' =>
            'Pelumas mulai berkurang atau kotor, '
            . 'terdapat rembesan ringan, atau suara '
            . 'operasi mulai berubah.',

        'danger_description' =>
            'Pelumas sangat kurang atau kotor, '
            . 'terdapat kebocoran jelas, atau terdengar '
            . 'suara gesekan atau ketukan kuat.',

        'allows_na' => 1,
        'display_order' => 4,
    ],

    [
        'item_code' => 'M5',
        'item_name' => 'Mechanical Seal',
        'inspected_part' =>
            'Kebocoran, kondisi fisik, '
            . 'dan kemampuan penyekatan.',

        'normal_description' =>
            'Tidak terdapat kebocoran atau '
            . 'kerusakan yang terlihat.',

        'warning_description' =>
            'Terdapat rembesan ringan atau penumpukan '
            . 'bahan di sekitar seal.',

        'danger_description' =>
            'Terdapat kebocoran jelas atau berkelanjutan, '
            . 'kerusakan fisik, atau kemampuan '
            . 'penyekatan telah menurun.',

        'allows_na' => 1,
        'display_order' => 5,
    ],

    [
        'item_code' => 'M6',
        'item_name' => 'Kondisi Fisik dan Suara Operasi',
        'inspected_part' =>
            'Bodi motor, tutup kipas, kipas, jalur '
            . 'ventilasi, korosi, dan suara umum.',

        'normal_description' =>
            'Bodi, kipas, dan ventilasi dalam kondisi '
            . 'baik serta tidak terdapat suara atau '
            . 'kerusakan yang tidak normal.',

        'warning_description' =>
            'Terdapat kotoran, korosi ringan, kipas '
            . 'mulai tidak stabil, atau perubahan '
            . 'suara operasi yang masih ringan.',

        'danger_description' =>
            'Kipas rusak, ventilasi tertutup berat, '
            . 'bodi retak, korosi berat, atau terdapat '
            . 'bunyi gesekan atau ketukan kuat.',

        'allows_na' => 0,
        'display_order' => 6,
    ],
];

// Checklist Elektrikal C5

$electricalItems = [
    [
        'item_code' => 'E1',
        'item_name' => 'Kabel Motor',
        'inspected_part' =>
            'Selubung kabel, isolasi, cable gland, '
            . 'posisi, dan kerusakan fisik.',

        'normal_description' =>
            'Kabel utuh, terikat dengan baik, '
            . 'tidak retak, tidak terkelupas, '
            . 'dan tidak berubah warna.',

        'warning_description' =>
            'Terdapat retak ringan, ikatan kurang baik, '
            . 'atau perubahan warna tahap awal.',

        'danger_description' =>
            'Isolasi terkelupas, penghantar terlihat, '
            . 'kabel terbakar atau meleleh, '
            . 'atau terdapat kerusakan berat.',

        'allows_na' => 0,
        'display_order' => 1,
    ],

    [
        'item_code' => 'E2',
        'item_name' => 'Terminal dan Sambungan',
        'inspected_part' =>
            'Terminal motor, sambungan kabel, korosi, '
            . 'kebersihan, dan tanda panas berlebih.',

        'normal_description' =>
            'Terminal bersih, kering, kuat, '
            . 'dan tidak mengalami perubahan warna.',

        'warning_description' =>
            'Terdapat korosi ringan, debu, atau '
            . 'indikasi sambungan mulai longgar.',

        'danger_description' =>
            'Terminal longgar, terbakar, meleleh, '
            . 'berkarat berat, atau berubah warna '
            . 'akibat panas berlebih.',

        'allows_na' => 0,
        'display_order' => 2,
    ],

    [
        'item_code' => 'E3',
        'item_name' => 'Grounding',
        'inspected_part' =>
            'Kabel pembumian, terminal pembumian, '
            . 'kekencangan, dan korosi.',

        'normal_description' =>
            'Grounding terpasang dengan baik, kuat, '
            . 'dan tidak mengalami kerusakan.',

        'warning_description' =>
            'Grounding mulai longgar, kotor, '
            . 'atau terdapat korosi ringan.',

        'danger_description' =>
            'Grounding tidak terpasang, terputus, '
            . 'longgar berat, atau terminal rusak.',

        'allows_na' => 0,
        'display_order' => 3,
    ],

    [
        'item_code' => 'E4',
        'item_name' => 'VFD atau Inverter',
        'inspected_part' =>
            'Tampilan, alarm atau fault, kipas, '
            . 'ventilasi, kebersihan, dan tanda panas.',

        'normal_description' =>
            'Tidak terdapat alarm, tampilan normal, '
            . 'serta kipas dan ventilasi berfungsi baik.',

        'warning_description' =>
            'Terdapat debu, suara kipas berubah, '
            . 'riwayat alarm sesekali, atau ventilasi '
            . 'mulai kotor.',

        'danger_description' =>
            'Alarm atau fault aktif atau berulang, '
            . 'kipas tidak berfungsi, panas berlebih, '
            . 'tampilan mati, atau komponen terbakar.',

        'allows_na' => 0,
        'display_order' => 4,
    ],

    [
        'item_code' => 'E5',
        'item_name' => 'Kontaktor dan Overload',
        'inspected_part' =>
            'Kondisi kontak, koil, mekanisme, suara '
            . 'operasi, pengaturan, dan riwayat trip.',

        'normal_description' =>
            'Beroperasi normal, tidak bergetar, '
            . 'tidak aus berat, dan tidak terjadi '
            . 'trip yang tidak normal.',

        'warning_description' =>
            'Kontak mulai aus atau berubah warna, '
            . 'terdapat dengung ringan, atau pernah '
            . 'terjadi trip yang tidak rutin.',

        'danger_description' =>
            'Kontak terbakar atau menempel, kontaktor '
            . 'bergetar keras, koil rusak, atau overload '
            . 'sering trip tanpa penyebab operasi yang jelas.',

        'allows_na' => 0,
        'display_order' => 5,
    ],

    [
        'item_code' => 'E6',
        'item_name' => 'Panel dan Perangkat Proteksi',
        'inspected_part' =>
            'Panel, MCB atau MCCB, sekring, relai, '
            . 'ventilasi, kebersihan, dan kelembapan.',

        'normal_description' =>
            'Panel bersih, kering, tertutup dengan baik, '
            . 'serta ventilasi dan perangkat '
            . 'proteksi berfungsi normal.',

        'warning_description' =>
            'Terdapat debu, kelembapan ringan, '
            . 'indikator tidak bekerja, atau '
            . 'ventilasi mulai kotor.',

        'danger_description' =>
            'Panel basah, kotor berat, terdapat bekas '
            . 'terbakar, perangkat proteksi rusak, '
            . 'atau ventilasi tidak bekerja.',

        'allows_na' => 0,
        'display_order' => 6,
    ],
];

// Jalankan Proses Seed

try {
    $pdo->beginTransaction();

    /*
 * Simpan Data Pengguna
 * Password hanya disimpan saat akun belum tersedia.
 * Seed hanya memastikan akun awal Administrator, Supervisor, dan satu
 * Teknisi tersedia. Teknisi tambahan dibuat melalui menu Data Pengguna.
 * Akun yang sudah ada tidak ditimpa, sehingga perubahan tetap dipertahankan.
 */

    $existingRoleStatement = $pdo->prepare(
        "SELECT
            id

         FROM users

         WHERE role = :role

         LIMIT 1"
    );

    $insertUserStatement = $pdo->prepare(
        "INSERT INTO users (
            name,
            username,
            password_hash,
            role,
            is_active
        ) VALUES (
            :name,
            :username,
            :password_hash,
            :role,
            1
        )"
    );

    foreach ($users as $user) {
        /*
 * Akun Awal per Peran
 * Seed tidak membuat akun awal baru apabila role tersebut sudah tersedia.
 * Nama, username, password, dan status akun yang pernah diubah Admin
 * tetap dipertahankan.
 */

        $existingRoleStatement->execute([
            'role' => $user['role'],
        ]);

        if ($existingRoleStatement->fetchColumn() !== false) {
            continue;
        }

        $passwordHash = password_hash(
            $user['password'],
            PASSWORD_DEFAULT
        );

        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new RuntimeException(
                'Password pengguna gagal dibuat.'
            );
        }

        $insertUserStatement->execute([
            'name' => $user['name'],
            'username' => $user['username'],
            'password_hash' => $passwordHash,
            'role' => $user['role'],
        ]);
    }

    // Simpan Data Enam Motor

    $machineStatement = $pdo->prepare(
        "INSERT INTO machines (
            alternative_code,
            machine_code,
            machine_number,
            motor_function,
            motor_name,
            brand,
            voltage,
            rated_current,
            rated_frequency,
            rated_rpm,
            power_hp,
            number_of_poles,
            location,
            status,
            notes
        ) VALUES (
            :alternative_code,
            :machine_code,
            :machine_number,
            :motor_function,
            :motor_name,
            :brand,
            :voltage,
            :rated_current,
            :rated_frequency,
            :rated_rpm,
            :power_hp,
            :number_of_poles,
            :location,
            :status,
            :notes
        )
        ON CONFLICT(alternative_code) DO UPDATE SET
            machine_code = excluded.machine_code,
            machine_number = excluded.machine_number,
            motor_function = excluded.motor_function,
            motor_name = excluded.motor_name,
            brand = excluded.brand,
            voltage = excluded.voltage,
            rated_current = excluded.rated_current,
            rated_frequency = excluded.rated_frequency,
            rated_rpm = excluded.rated_rpm,
            power_hp = excluded.power_hp,
            number_of_poles = excluded.number_of_poles,
            location = excluded.location,
            status = excluded.status,
            notes = excluded.notes,
            updated_at = datetime('now', '+7 hours')"
    );

    foreach ($machines as $machine) {
        $machineStatement->execute([
            'alternative_code' =>
                $machine['alternative_code'],

            'machine_code' =>
                $machine['machine_code'],

            'machine_number' =>
                $machine['machine_number'],

            'motor_function' =>
                $machine['motor_function'],

            'motor_name' =>
                $machine['motor_name'],

            'brand' =>
                $machine['brand'],

            'voltage' =>
                $machine['voltage'],

            'rated_current' =>
                $machine['rated_current'],

            'rated_frequency' =>
                $machine['rated_frequency'],

            'rated_rpm' =>
                $machine['rated_rpm'],

            'power_hp' =>
                $machine['power_hp'],

            'number_of_poles' =>
                $machine['number_of_poles'],

            'location' =>
                $machine['location'],

            'status' =>
                $machine['status'],

            'notes' =>
                $machine['notes'],
        ]);
    }

    // Simpan Data Kriteria

    $criterionStatement = $pdo->prepare(
        "INSERT INTO criteria (
            code,
            name,
            description,
            display_order,
            is_active
        ) VALUES (
            :code,
            :name,
            :description,
            :display_order,
            1
        )
        ON CONFLICT(code) DO UPDATE SET
            name = excluded.name,
            description = excluded.description,
            display_order = excluded.display_order,
            is_active = 1,
            updated_at = datetime('now', '+7 hours')"
    );

    foreach ($criteria as $criterion) {
        $criterionStatement->execute([
            'code' =>
                $criterion['code'],

            'name' =>
                $criterion['name'],

            'description' =>
                $criterion['description'],

            'display_order' =>
                $criterion['display_order'],
        ]);
    }

    // Ambil ID Kriteria C4 dan C5

    $criterionIdStatement = $pdo->prepare(
        "SELECT id
         FROM criteria
         WHERE code = :code
         LIMIT 1"
    );

    $criterionIdStatement->execute([
        'code' => 'C4',
    ]);

    $mechanicalCriterionId =
        $criterionIdStatement->fetchColumn();

    $criterionIdStatement->execute([
        'code' => 'C5',
    ]);

    $electricalCriterionId =
        $criterionIdStatement->fetchColumn();

    if (
        $mechanicalCriterionId === false
        || $electricalCriterionId === false
    ) {
        throw new RuntimeException(
            'Kriteria C4 atau C5 tidak ditemukan.'
        );
    }

    /*
 * Nonaktifkan Checklist Lama yang Tidak Digunakan
 * Langkah ini menjaga agar sistem hanya memakai:
 * M1 sampai M6
 * E1 sampai E6
 */

    $pdo->exec(
        "UPDATE checklist_items
         SET
             is_active = 0,
             updated_at = datetime('now', '+7 hours')
         WHERE item_code NOT IN (
             'M1',
             'M2',
             'M3',
             'M4',
             'M5',
             'M6',
             'E1',
             'E2',
             'E3',
             'E4',
             'E5',
             'E6'
         )"
    );

    // Simpan Item Checklist

    $checklistStatement = $pdo->prepare(
        "INSERT INTO checklist_items (
            criterion_id,
            item_code,
            item_name,
            inspected_part,
            normal_description,
            warning_description,
            danger_description,
            allows_na,
            display_order,
            is_active
        ) VALUES (
            :criterion_id,
            :item_code,
            :item_name,
            :inspected_part,
            :normal_description,
            :warning_description,
            :danger_description,
            :allows_na,
            :display_order,
            1
        )
        ON CONFLICT(item_code) DO UPDATE SET
            criterion_id = excluded.criterion_id,
            item_name = excluded.item_name,
            inspected_part = excluded.inspected_part,
            normal_description = excluded.normal_description,
            warning_description = excluded.warning_description,
            danger_description = excluded.danger_description,
            allows_na = excluded.allows_na,
            display_order = excluded.display_order,
            is_active = 1,
            updated_at = datetime('now', '+7 hours')"
    );

    foreach ($mechanicalItems as $item) {
        $checklistStatement->execute([
            'criterion_id' =>
                (int) $mechanicalCriterionId,

            'item_code' =>
                $item['item_code'],

            'item_name' =>
                $item['item_name'],

            'inspected_part' =>
                $item['inspected_part'],

            'normal_description' =>
                $item['normal_description'],

            'warning_description' =>
                $item['warning_description'],

            'danger_description' =>
                $item['danger_description'],

            'allows_na' =>
                $item['allows_na'],

            'display_order' =>
                $item['display_order'],
        ]);
    }

    foreach ($electricalItems as $item) {
        $checklistStatement->execute([
            'criterion_id' =>
                (int) $electricalCriterionId,

            'item_code' =>
                $item['item_code'],

            'item_name' =>
                $item['item_name'],

            'inspected_part' =>
                $item['inspected_part'],

            'normal_description' =>
                $item['normal_description'],

            'warning_description' =>
                $item['warning_description'],

            'danger_description' =>
                $item['danger_description'],

            'allows_na' =>
                $item['allows_na'],

            'display_order' =>
                $item['display_order'],
        ]);
    }

    // Validasi Jumlah Data Awal

    $totalMachines = (int) $pdo
        ->query(
            "SELECT COUNT(*)
             FROM machines
             WHERE alternative_code IN (
                 'A1',
                 'A2',
                 'A3',
                 'A4',
                 'A5',
                 'A6'
             )"
        )
        ->fetchColumn();

    $totalCriteria = (int) $pdo
        ->query(
            "SELECT COUNT(*)
             FROM criteria
             WHERE is_active = 1"
        )
        ->fetchColumn();

    $totalChecklistItems = (int) $pdo
        ->query(
            "SELECT COUNT(*)
             FROM checklist_items
             WHERE is_active = 1"
        )
        ->fetchColumn();

    $totalMechanicalItems = (int) $pdo
        ->query(
            "SELECT COUNT(*)
             FROM checklist_items AS ci
             INNER JOIN criteria AS c
                 ON c.id = ci.criterion_id
             WHERE c.code = 'C4'
               AND ci.is_active = 1"
        )
        ->fetchColumn();

    $totalElectricalItems = (int) $pdo
        ->query(
            "SELECT COUNT(*)
             FROM checklist_items AS ci
             INNER JOIN criteria AS c
                 ON c.id = ci.criterion_id
             WHERE c.code = 'C5'
               AND ci.is_active = 1"
        )
        ->fetchColumn();

    $activeSupervisorCount = (int) $pdo
        ->query(
            "SELECT COUNT(*)
             FROM users
             WHERE role = 'supervisor'
               AND is_active = 1"
        )
        ->fetchColumn();

    if ($totalMachines !== 6) {
        throw new RuntimeException(
            'Jumlah alternatif motor harus tepat enam.'
        );
    }

    if ($totalCriteria !== 5) {
        throw new RuntimeException(
            'Jumlah kriteria aktif harus tepat lima.'
        );
    }

    if ($totalChecklistItems !== 12) {
        throw new RuntimeException(
            'Jumlah checklist aktif harus tepat dua belas.'
        );
    }

    if ($totalMechanicalItems !== 6) {
        throw new RuntimeException(
            'Checklist mekanikal harus berjumlah enam.'
        );
    }

    if ($totalElectricalItems !== 6) {
        throw new RuntimeException(
            'Checklist elektrikal harus berjumlah enam.'
        );
    }

    if ($activeSupervisorCount !== 1) {
        throw new RuntimeException(
            'Sistem harus memiliki tepat satu '
            . 'Supervisor Maintenance aktif.'
        );
    }

    // Periksa Foreign Key

    $foreignKeyViolations = $pdo
        ->query('PRAGMA foreign_key_check')
        ->fetchAll();

    if ($foreignKeyViolations !== []) {
        throw new RuntimeException(
            'Ditemukan pelanggaran foreign key '
            . 'pada data awal.'
        );
    }

    // Simpan Seluruh Perubahan

    $pdo->commit();

    // Periksa Integritas Database

    $integrityResult = (string) $pdo
        ->query('PRAGMA integrity_check')
        ->fetchColumn();

    if (strtolower(trim($integrityResult)) !== 'ok') {
        throw new RuntimeException(
            'Integritas database bermasalah: '
            . $integrityResult
        );
    }

    // Ringkasan Hasil Seed

    $summary = [
        'users' => (int) $pdo
            ->query(
                'SELECT COUNT(*) FROM users'
            )
            ->fetchColumn(),

        'machines' => (int) $pdo
            ->query(
                'SELECT COUNT(*) FROM machines'
            )
            ->fetchColumn(),

        'criteria' => (int) $pdo
            ->query(
                "SELECT COUNT(*)
                 FROM criteria
                 WHERE is_active = 1"
            )
            ->fetchColumn(),

        'checklist_items' => (int) $pdo
            ->query(
                "SELECT COUNT(*)
                 FROM checklist_items
                 WHERE is_active = 1"
            )
            ->fetchColumn(),

        'mechanical_items' => $totalMechanicalItems,

        'electrical_items' => $totalElectricalItems,

        'na_items' => (int) $pdo
            ->query(
                "SELECT COUNT(*)
                 FROM checklist_items
                 WHERE allows_na = 1
                   AND is_active = 1"
            )
            ->fetchColumn(),
    ];

    echo 'Data awal berhasil dimasukkan.'
        . PHP_EOL;

    echo 'Integritas database    : OK'
        . PHP_EOL;

    echo 'Jumlah pengguna        : '
        . $summary['users']
        . PHP_EOL;

    echo 'Jumlah motor           : '
        . $summary['machines']
        . PHP_EOL;

    echo 'Jumlah kriteria aktif  : '
        . $summary['criteria']
        . PHP_EOL;

    echo 'Checklist mekanikal    : '
        . $summary['mechanical_items']
        . PHP_EOL;

    echo 'Checklist elektrikal   : '
        . $summary['electrical_items']
        . PHP_EOL;

    echo 'Jumlah checklist aktif : '
        . $summary['checklist_items']
        . PHP_EOL;

    echo 'Item mendukung Tidak Tersedia     : '
        . $summary['na_items']
        . PHP_EOL;

    echo PHP_EOL;

    echo 'Akun awal untuk database baru:'
        . PHP_EOL;

    echo 'Admin      : admin / admin123'
        . PHP_EOL;

    echo 'Supervisor : supervisor / supervisor123'
        . PHP_EOL;

    echo 'Teknisi    : teknisi / teknisi123'
        . PHP_EOL;

    echo PHP_EOL;

    echo 'Catatan:'
        . PHP_EOL;

    echo '- Data nameplate yang NULL wajib dilengkapi Admin.'
        . PHP_EOL;

    echo '- Jangan menebak arus atau frekuensi nominal motor.'
        . PHP_EOL;

    echo '- Password awal harus diubah setelah sistem siap.'
        . PHP_EOL;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Seed database gagal: '
        . $exception->getMessage()
    );

    fwrite(
        STDERR,
        'Seed database gagal: '
        . $exception->getMessage()
        . PHP_EOL
    );

    exit(1);
}
