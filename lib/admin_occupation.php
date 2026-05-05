<?php
declare(strict_types=1);

/**
 * @return array<string, string>
 */
function admin_employment_type_options(): array
{
    return [
        'GOVERNMENT' => 'ราชการ',
        'PRIVATE' => 'เอกชน',
        'AGRICULTURE' => 'เกษตร',
    ];
}

function admin_employment_type_label(string $type): string
{
    $options = admin_employment_type_options();
    return $options[$type] ?? $type;
}

/**
 * @return string[]
 */
function admin_thai_provinces(): array
{
    return [
        'กรุงเทพมหานคร', 'กระบี่', 'กาญจนบุรี', 'กาฬสินธุ์', 'กำแพงเพชร', 'ขอนแก่น',
        'จันทบุรี', 'ฉะเชิงเทรา', 'ชลบุรี', 'ชัยนาท', 'ชัยภูมิ', 'ชุมพร', 'เชียงราย',
        'เชียงใหม่', 'ตรัง', 'ตราด', 'ตาก', 'นครนายก', 'นครปฐม', 'นครพนม',
        'นครราชสีมา', 'นครศรีธรรมราช', 'นครสวรรค์', 'นนทบุรี', 'นราธิวาส', 'น่าน',
        'บึงกาฬ', 'บุรีรัมย์', 'ปทุมธานี', 'ประจวบคีรีขันธ์', 'ปราจีนบุรี', 'ปัตตานี',
        'พระนครศรีอยุธยา', 'พะเยา', 'พังงา', 'พัทลุง', 'พิจิตร', 'พิษณุโลก', 'เพชรบุรี',
        'เพชรบูรณ์', 'แพร่', 'ภูเก็ต', 'มหาสารคาม', 'มุกดาหาร', 'แม่ฮ่องสอน',
        'ยโสธร', 'ยะลา', 'ร้อยเอ็ด', 'ระนอง', 'ระยอง', 'ราชบุรี', 'ลพบุรี', 'ลำปาง',
        'ลำพูน', 'เลย', 'ศรีสะเกษ', 'สกลนคร', 'สงขลา', 'สตูล', 'สมุทรปราการ',
        'สมุทรสงคราม', 'สมุทรสาคร', 'สระแก้ว', 'สระบุรี', 'สิงห์บุรี', 'สุโขทัย',
        'สุพรรณบุรี', 'สุราษฎร์ธานี', 'สุรินทร์', 'หนองคาย', 'หนองบัวลำภู',
        'อ่างทอง', 'อำนาจเจริญ', 'อุดรธานี', 'อุตรดิตถ์', 'อุทัยธานี', 'อุบลราชธานี',
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function admin_default_occupation_catalog(): array
{
    return [
        ['code' => 'OCC001', 'name' => 'ครูโรงเรียนรัฐบาล', 'type' => 'GOVERNMENT', 'avg_income_min' => 18000, 'avg_income_default' => 26000, 'avg_income_max' => 42000, 'agriculture_detail' => ''],
        ['code' => 'OCC002', 'name' => 'พยาบาลโรงพยาบาลรัฐ', 'type' => 'GOVERNMENT', 'avg_income_min' => 22000, 'avg_income_default' => 32000, 'avg_income_max' => 52000, 'agriculture_detail' => ''],
        ['code' => 'OCC003', 'name' => 'เจ้าหน้าที่ปกครอง', 'type' => 'GOVERNMENT', 'avg_income_min' => 17000, 'avg_income_default' => 25000, 'avg_income_max' => 40000, 'agriculture_detail' => ''],
        ['code' => 'OCC004', 'name' => 'เจ้าหน้าที่สาธารณสุข', 'type' => 'GOVERNMENT', 'avg_income_min' => 18000, 'avg_income_default' => 27000, 'avg_income_max' => 43000, 'agriculture_detail' => ''],
        ['code' => 'OCC005', 'name' => 'ตำรวจ', 'type' => 'GOVERNMENT', 'avg_income_min' => 20000, 'avg_income_default' => 30000, 'avg_income_max' => 48000, 'agriculture_detail' => ''],
        ['code' => 'OCC006', 'name' => 'ทหารประจำการ', 'type' => 'GOVERNMENT', 'avg_income_min' => 18000, 'avg_income_default' => 28000, 'avg_income_max' => 45000, 'agriculture_detail' => ''],
        ['code' => 'OCC007', 'name' => 'เจ้าพนักงานการเงินภาครัฐ', 'type' => 'GOVERNMENT', 'avg_income_min' => 19000, 'avg_income_default' => 29000, 'avg_income_max' => 46000, 'agriculture_detail' => ''],
        ['code' => 'OCC008', 'name' => 'เจ้าหน้าที่เทศบาล', 'type' => 'GOVERNMENT', 'avg_income_min' => 16000, 'avg_income_default' => 24000, 'avg_income_max' => 39000, 'agriculture_detail' => ''],
        ['code' => 'OCC009', 'name' => 'เจ้าพนักงานที่ดิน', 'type' => 'GOVERNMENT', 'avg_income_min' => 19000, 'avg_income_default' => 30000, 'avg_income_max' => 47000, 'agriculture_detail' => ''],
        ['code' => 'OCC010', 'name' => 'เจ้าหน้าที่ศุลกากร', 'type' => 'GOVERNMENT', 'avg_income_min' => 22000, 'avg_income_default' => 33000, 'avg_income_max' => 54000, 'agriculture_detail' => ''],

        ['code' => 'OCC011', 'name' => 'พนักงานบัญชี', 'type' => 'PRIVATE', 'avg_income_min' => 17000, 'avg_income_default' => 26000, 'avg_income_max' => 45000, 'agriculture_detail' => ''],
        ['code' => 'OCC012', 'name' => 'วิศวกรโยธา', 'type' => 'PRIVATE', 'avg_income_min' => 25000, 'avg_income_default' => 42000, 'avg_income_max' => 80000, 'agriculture_detail' => ''],
        ['code' => 'OCC013', 'name' => 'วิศวกรไฟฟ้า', 'type' => 'PRIVATE', 'avg_income_min' => 26000, 'avg_income_default' => 43000, 'avg_income_max' => 82000, 'agriculture_detail' => ''],
        ['code' => 'OCC014', 'name' => 'พนักงานขาย', 'type' => 'PRIVATE', 'avg_income_min' => 15000, 'avg_income_default' => 24000, 'avg_income_max' => 42000, 'agriculture_detail' => ''],
        ['code' => 'OCC015', 'name' => 'เจ้าหน้าที่บริการลูกค้า', 'type' => 'PRIVATE', 'avg_income_min' => 15000, 'avg_income_default' => 22000, 'avg_income_max' => 36000, 'agriculture_detail' => ''],
        ['code' => 'OCC016', 'name' => 'โปรแกรมเมอร์', 'type' => 'PRIVATE', 'avg_income_min' => 28000, 'avg_income_default' => 45000, 'avg_income_max' => 90000, 'agriculture_detail' => ''],
        ['code' => 'OCC017', 'name' => 'นักวิเคราะห์ข้อมูล', 'type' => 'PRIVATE', 'avg_income_min' => 30000, 'avg_income_default' => 50000, 'avg_income_max' => 95000, 'agriculture_detail' => ''],
        ['code' => 'OCC018', 'name' => 'ช่างเทคนิคโรงงาน', 'type' => 'PRIVATE', 'avg_income_min' => 18000, 'avg_income_default' => 28000, 'avg_income_max' => 45000, 'agriculture_detail' => ''],
        ['code' => 'OCC019', 'name' => 'พนักงานขับรถขนส่ง', 'type' => 'PRIVATE', 'avg_income_min' => 17000, 'avg_income_default' => 26000, 'avg_income_max' => 43000, 'agriculture_detail' => ''],
        ['code' => 'OCC020', 'name' => 'เจ้าของร้านค้าปลีก', 'type' => 'PRIVATE', 'avg_income_min' => 18000, 'avg_income_default' => 35000, 'avg_income_max' => 90000, 'agriculture_detail' => ''],
        ['code' => 'OCC021', 'name' => 'ผู้จัดการร้าน', 'type' => 'PRIVATE', 'avg_income_min' => 22000, 'avg_income_default' => 35000, 'avg_income_max' => 65000, 'agriculture_detail' => ''],
        ['code' => 'OCC022', 'name' => 'พนักงานโรงแรม', 'type' => 'PRIVATE', 'avg_income_min' => 15000, 'avg_income_default' => 23000, 'avg_income_max' => 40000, 'agriculture_detail' => ''],
        ['code' => 'OCC023', 'name' => 'พนักงานร้านอาหาร', 'type' => 'PRIVATE', 'avg_income_min' => 14000, 'avg_income_default' => 21000, 'avg_income_max' => 35000, 'agriculture_detail' => ''],
        ['code' => 'OCC024', 'name' => 'ช่างเสริมสวย', 'type' => 'PRIVATE', 'avg_income_min' => 15000, 'avg_income_default' => 27000, 'avg_income_max' => 60000, 'agriculture_detail' => ''],
        ['code' => 'OCC025', 'name' => 'ฟรีแลนซ์กราฟิกดีไซน์', 'type' => 'PRIVATE', 'avg_income_min' => 16000, 'avg_income_default' => 30000, 'avg_income_max' => 70000, 'agriculture_detail' => ''],
        ['code' => 'OCC026', 'name' => 'ผู้ดูแลคลังสินค้า', 'type' => 'PRIVATE', 'avg_income_min' => 17000, 'avg_income_default' => 25000, 'avg_income_max' => 42000, 'agriculture_detail' => ''],
        ['code' => 'OCC027', 'name' => 'พนักงานประกันภัย', 'type' => 'PRIVATE', 'avg_income_min' => 18000, 'avg_income_default' => 30000, 'avg_income_max' => 60000, 'agriculture_detail' => ''],
        ['code' => 'OCC028', 'name' => 'พนักงานธนาคารเอกชน', 'type' => 'PRIVATE', 'avg_income_min' => 22000, 'avg_income_default' => 36000, 'avg_income_max' => 70000, 'agriculture_detail' => ''],
        ['code' => 'OCC029', 'name' => 'ธุรกิจขายของออนไลน์', 'type' => 'PRIVATE', 'avg_income_min' => 15000, 'avg_income_default' => 32000, 'avg_income_max' => 120000, 'agriculture_detail' => ''],
        ['code' => 'OCC030', 'name' => 'ตัวแทนอสังหาริมทรัพย์', 'type' => 'PRIVATE', 'avg_income_min' => 18000, 'avg_income_default' => 38000, 'avg_income_max' => 150000, 'agriculture_detail' => ''],

        ['code' => 'OCC031', 'name' => 'เกษตรกรทำนาข้าว', 'type' => 'AGRICULTURE', 'avg_income_min' => 12000, 'avg_income_default' => 23000, 'avg_income_max' => 60000, 'agriculture_detail' => 'ปลูกข้าวนาปี/นาปรังและขายผลผลิตผ่านโรงสีหรือสหกรณ์'],
        ['code' => 'OCC032', 'name' => 'เกษตรกรปลูกอ้อย', 'type' => 'AGRICULTURE', 'avg_income_min' => 13000, 'avg_income_default' => 25000, 'avg_income_max' => 70000, 'agriculture_detail' => 'ปลูกอ้อยส่งโรงงานน้ำตาลและบริหารรอบตัดอ้อยตามฤดูกาล'],
        ['code' => 'OCC033', 'name' => 'เกษตรกรปลูกมันสำปะหลัง', 'type' => 'AGRICULTURE', 'avg_income_min' => 12000, 'avg_income_default' => 24000, 'avg_income_max' => 65000, 'agriculture_detail' => 'ปลูกมันสำปะหลังและขายหัวมันสดหรือมันเส้นให้ลานรับซื้อ'],
        ['code' => 'OCC034', 'name' => 'เกษตรกรปลูกข้าวโพดเลี้ยงสัตว์', 'type' => 'AGRICULTURE', 'avg_income_min' => 13000, 'avg_income_default' => 26000, 'avg_income_max' => 70000, 'agriculture_detail' => 'ปลูกข้าวโพดและส่งผลผลิตให้โรงงานอาหารสัตว์'],
        ['code' => 'OCC035', 'name' => 'เกษตรกรสวนยางพารา', 'type' => 'AGRICULTURE', 'avg_income_min' => 14000, 'avg_income_default' => 28000, 'avg_income_max' => 85000, 'agriculture_detail' => 'กรีดยางพาราและจำหน่ายน้ำยางสดหรือยางแผ่น'],
        ['code' => 'OCC036', 'name' => 'เกษตรกรสวนปาล์มน้ำมัน', 'type' => 'AGRICULTURE', 'avg_income_min' => 14000, 'avg_income_default' => 29000, 'avg_income_max' => 90000, 'agriculture_detail' => 'ดูแลสวนปาล์มและขายทะลายปาล์มเข้าลานเท'],
        ['code' => 'OCC037', 'name' => 'เกษตรกรสวนทุเรียน', 'type' => 'AGRICULTURE', 'avg_income_min' => 15000, 'avg_income_default' => 40000, 'avg_income_max' => 150000, 'agriculture_detail' => 'ดูแลสวนทุเรียน คัดเกรดผลผลิต และขายเข้าตลาดค้าส่ง/ส่งออก'],
        ['code' => 'OCC038', 'name' => 'เกษตรกรสวนลำไย', 'type' => 'AGRICULTURE', 'avg_income_min' => 14000, 'avg_income_default' => 30000, 'avg_income_max' => 95000, 'agriculture_detail' => 'ผลิตลำไยสดและลำไยอบแห้งเพื่อจำหน่ายตามฤดูกาล'],
        ['code' => 'OCC039', 'name' => 'เกษตรกรสวนมังคุด', 'type' => 'AGRICULTURE', 'avg_income_min' => 13000, 'avg_income_default' => 28000, 'avg_income_max' => 80000, 'agriculture_detail' => 'ดูแลสวนมังคุดและกระจายผลผลิตผ่านตลาดกลาง'],
        ['code' => 'OCC040', 'name' => 'เกษตรกรสวนมะพร้าว', 'type' => 'AGRICULTURE', 'avg_income_min' => 13000, 'avg_income_default' => 26000, 'avg_income_max' => 70000, 'agriculture_detail' => 'ปลูกมะพร้าวน้ำหอมหรือมะพร้าวแกงและขายให้โรงคัดบรรจุ'],
        ['code' => 'OCC041', 'name' => 'เกษตรกรปลูกกาแฟ', 'type' => 'AGRICULTURE', 'avg_income_min' => 14000, 'avg_income_default' => 30000, 'avg_income_max' => 90000, 'agriculture_detail' => 'ปลูกกาแฟอาราบิก้าหรือโรบัสต้าและแปรรูปเมล็ดกาแฟ'],
        ['code' => 'OCC042', 'name' => 'เกษตรกรปลูกชา', 'type' => 'AGRICULTURE', 'avg_income_min' => 13000, 'avg_income_default' => 26000, 'avg_income_max' => 75000, 'agriculture_detail' => 'ปลูกชา เก็บยอดชา และแปรรูปเป็นชาแห้ง'],
        ['code' => 'OCC043', 'name' => 'เกษตรกรปลูกพริก', 'type' => 'AGRICULTURE', 'avg_income_min' => 12000, 'avg_income_default' => 24000, 'avg_income_max' => 68000, 'agriculture_detail' => 'ปลูกพริกสดและพริกแห้งส่งตลาดกลางหรือโรงงาน'],
        ['code' => 'OCC044', 'name' => 'เกษตรกรปลูกหอมแดง', 'type' => 'AGRICULTURE', 'avg_income_min' => 12000, 'avg_income_default' => 23000, 'avg_income_max' => 65000, 'agriculture_detail' => 'ปลูกหอมแดงและบริหารสต็อกในช่วงราคาผันผวน'],
        ['code' => 'OCC045', 'name' => 'เกษตรกรปลูกมันฝรั่ง', 'type' => 'AGRICULTURE', 'avg_income_min' => 13000, 'avg_income_default' => 25000, 'avg_income_max' => 70000, 'agriculture_detail' => 'ปลูกมันฝรั่งส่งโรงงานแปรรูปและตลาดสด'],
        ['code' => 'OCC046', 'name' => 'เกษตรกรผักไฮโดรโปนิกส์', 'type' => 'AGRICULTURE', 'avg_income_min' => 16000, 'avg_income_default' => 32000, 'avg_income_max' => 95000, 'agriculture_detail' => 'ปลูกผักระบบน้ำหมุนเวียนและขายให้ร้านอาหาร/ซูเปอร์มาร์เก็ต'],
        ['code' => 'OCC047', 'name' => 'เกษตรกรเพาะเห็ด', 'type' => 'AGRICULTURE', 'avg_income_min' => 14000, 'avg_income_default' => 27000, 'avg_income_max' => 78000, 'agriculture_detail' => 'เพาะเห็ดนางฟ้าหรือเห็ดนางรมและจำหน่ายรายวัน'],
        ['code' => 'OCC048', 'name' => 'ผู้เลี้ยงไก่ไข่', 'type' => 'AGRICULTURE', 'avg_income_min' => 15000, 'avg_income_default' => 30000, 'avg_income_max' => 90000, 'agriculture_detail' => 'เลี้ยงแม่ไก่ไข่และกระจายไข่สดให้ตลาดและร้านค้า'],
        ['code' => 'OCC049', 'name' => 'ผู้เลี้ยงไก่เนื้อ', 'type' => 'AGRICULTURE', 'avg_income_min' => 15000, 'avg_income_default' => 32000, 'avg_income_max' => 100000, 'agriculture_detail' => 'เลี้ยงไก่เนื้อแบบรอบผลิตและส่งโรงชำแหละ'],
        ['code' => 'OCC050', 'name' => 'ผู้เลี้ยงสุกร', 'type' => 'AGRICULTURE', 'avg_income_min' => 16000, 'avg_income_default' => 34000, 'avg_income_max' => 110000, 'agriculture_detail' => 'เลี้ยงสุกรขุนและขายให้ผู้ค้าส่งหรือโรงฆ่าสัตว์'],
        ['code' => 'OCC051', 'name' => 'ผู้เลี้ยงโคเนื้อ', 'type' => 'AGRICULTURE', 'avg_income_min' => 15000, 'avg_income_default' => 30000, 'avg_income_max' => 95000, 'agriculture_detail' => 'เลี้ยงโคเนื้อเพื่อจำหน่ายเข้าตลาดนัดโคกระบือ'],
        ['code' => 'OCC052', 'name' => 'ผู้เลี้ยงโคนม', 'type' => 'AGRICULTURE', 'avg_income_min' => 17000, 'avg_income_default' => 36000, 'avg_income_max' => 105000, 'agriculture_detail' => 'รีดน้ำนมดิบส่งศูนย์รวบรวมน้ำนมและสหกรณ์โคนม'],
        ['code' => 'OCC053', 'name' => 'ผู้เลี้ยงแพะ', 'type' => 'AGRICULTURE', 'avg_income_min' => 13000, 'avg_income_default' => 25000, 'avg_income_max' => 70000, 'agriculture_detail' => 'เลี้ยงแพะเนื้อหรือแพะนมและขายตามคำสั่งซื้อ'],
        ['code' => 'OCC054', 'name' => 'ผู้เลี้ยงปลาในบ่อดิน', 'type' => 'AGRICULTURE', 'avg_income_min' => 14000, 'avg_income_default' => 28000, 'avg_income_max' => 85000, 'agriculture_detail' => 'เลี้ยงปลานิล/ปลาดุกในบ่อดินและจับขายตามรอบ'],
        ['code' => 'OCC055', 'name' => 'ผู้เลี้ยงกุ้งขาว', 'type' => 'AGRICULTURE', 'avg_income_min' => 18000, 'avg_income_default' => 38000, 'avg_income_max' => 130000, 'agriculture_detail' => 'เพาะเลี้ยงกุ้งขาวในบ่อและส่งโรงคัดบรรจุ'],
        ['code' => 'OCC056', 'name' => 'ผู้เพาะพันธุ์ปลาน้ำจืด', 'type' => 'AGRICULTURE', 'avg_income_min' => 14000, 'avg_income_default' => 29000, 'avg_income_max' => 85000, 'agriculture_detail' => 'เพาะลูกพันธุ์ปลาเพื่อขายให้เกษตรกรผู้เลี้ยง'],
        ['code' => 'OCC057', 'name' => 'ผู้เลี้ยงผึ้ง', 'type' => 'AGRICULTURE', 'avg_income_min' => 13000, 'avg_income_default' => 26000, 'avg_income_max' => 78000, 'agriculture_detail' => 'เลี้ยงผึ้งผลิตน้ำผึ้งและผลิตภัณฑ์จากผึ้ง'],
        ['code' => 'OCC058', 'name' => 'เกษตรกรปลูกสมุนไพร', 'type' => 'AGRICULTURE', 'avg_income_min' => 13000, 'avg_income_default' => 27000, 'avg_income_max' => 82000, 'agriculture_detail' => 'ปลูกฟ้าทะลายโจร ขมิ้นชัน หรือสมุนไพรเศรษฐกิจส่งโรงงานยา'],
        ['code' => 'OCC059', 'name' => 'เกษตรกรไม้ดอกไม้ประดับ', 'type' => 'AGRICULTURE', 'avg_income_min' => 14000, 'avg_income_default' => 30000, 'avg_income_max' => 90000, 'agriculture_detail' => 'เพาะปลูกไม้ดอกไม้ประดับเพื่อขายตลาดต้นไม้และงานอีเวนต์'],
        ['code' => 'OCC060', 'name' => 'เกษตรกรสวนผสมอินทรีย์', 'type' => 'AGRICULTURE', 'avg_income_min' => 14000, 'avg_income_default' => 29000, 'avg_income_max' => 88000, 'agriculture_detail' => 'ทำเกษตรผสมผสานอินทรีย์ ปลูกพืชหลายชนิดและขายตรงผู้บริโภค'],
    ];
}

function admin_ensure_master_occupation_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS master_occupation (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            record_uid VARCHAR(80) NOT NULL,
            version_no INT UNSIGNED NOT NULL DEFAULT 1,
            is_latest TINYINT(1) NOT NULL DEFAULT 1,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            occupation_code VARCHAR(60) NOT NULL,
            occupation_name VARCHAR(200) NOT NULL,
            employment_type VARCHAR(30) NOT NULL,
            province_name VARCHAR(120) NOT NULL,
            avg_income_min DECIMAL(12,2) NOT NULL DEFAULT 0,
            avg_income_max DECIMAL(12,2) NOT NULL DEFAULT 0,
            avg_income_default DECIMAL(12,2) NOT NULL DEFAULT 0,
            agriculture_detail TEXT NULL,
            data_json LONGTEXT NULL,
            created_by VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT '',
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_master_occupation_version (record_uid, version_no),
            KEY idx_master_occupation_latest (is_latest, is_deleted, occupation_code, province_name),
            KEY idx_master_occupation_type (employment_type, province_name)
        )"
    );
}

function admin_seed_default_occupations(PDO $pdo, string $actor = 'system_seed'): int
{
    $existing = (int)$pdo->query("SELECT COUNT(*) FROM master_occupation WHERE is_latest = 1")->fetchColumn();
    if ($existing > 0) {
        return 0;
    }

    $catalog = admin_default_occupation_catalog();
    $provinces = admin_thai_provinces();
    $provinceCount = count($provinces);
    $now = now_dt();

    $stmt = $pdo->prepare(
        "INSERT INTO master_occupation (
            record_uid, version_no, is_latest, is_deleted, occupation_code, occupation_name, employment_type, province_name,
            avg_income_min, avg_income_max, avg_income_default, agriculture_detail, data_json,
            created_by, created_at, updated_by, updated_at, deleted_by, deleted_at
        ) VALUES (
            :record_uid, 1, 1, 0, :occupation_code, :occupation_name, :employment_type, :province_name,
            :avg_income_min, :avg_income_max, :avg_income_default, :agriculture_detail, :data_json,
            :created_by, :created_at, :updated_by, :updated_at, NULL, NULL
        )"
    );

    $inserted = 0;
    foreach ($catalog as $index => $item) {
        $province = $provinces[$index % $provinceCount];
        $recordUid = sprintf('MOC-SEED-%04d', $index + 1);
        $stmt->execute([
            ':record_uid' => $recordUid,
            ':occupation_code' => (string)$item['code'],
            ':occupation_name' => (string)$item['name'],
            ':employment_type' => (string)$item['type'],
            ':province_name' => $province,
            ':avg_income_min' => (float)$item['avg_income_min'],
            ':avg_income_max' => (float)$item['avg_income_max'],
            ':avg_income_default' => (float)$item['avg_income_default'],
            ':agriculture_detail' => (string)$item['agriculture_detail'],
            ':data_json' => json_encode([
                'seed_source' => 'default_occupation_catalog_60',
                'province_group' => 'all_thailand',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':created_by' => $actor,
            ':created_at' => $now,
            ':updated_by' => $actor,
            ':updated_at' => $now,
        ]);
        $inserted++;
    }

    return $inserted;
}

/**
 * @param array<string,mixed> $input
 * @param array<string,bool> $provinceMap
 * @return array<string,mixed>
 */
function admin_validate_occupation_payload(array $input, array $provinceMap): array
{
    $occupationCode = strtoupper(trim((string)($input['occupation_code'] ?? '')));
    $occupationName = trim((string)($input['occupation_name'] ?? ''));
    $employmentType = strtoupper(trim((string)($input['employment_type'] ?? '')));
    $provinceName = trim((string)($input['province_name'] ?? ''));
    $agricultureDetail = trim((string)($input['agriculture_detail'] ?? ''));
    $noteText = trim((string)($input['note_text'] ?? ''));

    if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $occupationCode)) {
        throw new RuntimeException('รหัสอาชีพต้องเป็น A-Z, 0-9, _ หรือ - ความยาว 3-40 ตัวอักษร');
    }
    if ($occupationName === '') {
        throw new RuntimeException('กรุณากรอกชื่ออาชีพ');
    }

    $employmentOptions = admin_employment_type_options();
    if (!isset($employmentOptions[$employmentType])) {
        throw new RuntimeException('ประเภทอาชีพไม่ถูกต้อง');
    }
    if (!isset($provinceMap[$provinceName])) {
        throw new RuntimeException('กรุณาเลือกจังหวัดให้ถูกต้อง');
    }

    $incomeMin = parse_decimal_or_null($input['avg_income_min'] ?? null);
    $incomeMax = parse_decimal_or_null($input['avg_income_max'] ?? null);
    $incomeDefault = parse_decimal_or_null($input['avg_income_default'] ?? null);

    if ($incomeMin === null || $incomeMax === null || $incomeDefault === null) {
        throw new RuntimeException('กรุณากรอกรายได้ขั้นต่ำ รายได้เฉลี่ย และรายได้สูงสุด');
    }
    if ($incomeMin < 0 || $incomeMax < 0 || $incomeDefault < 0) {
        throw new RuntimeException('รายได้ต้องเป็นค่ามากกว่าหรือเท่ากับ 0');
    }
    if ($incomeMin > $incomeMax) {
        throw new RuntimeException('รายได้ขั้นต่ำต้องไม่มากกว่ารายได้สูงสุด');
    }
    if ($incomeDefault < $incomeMin || $incomeDefault > $incomeMax) {
        throw new RuntimeException('รายได้เฉลี่ยต้องอยู่ระหว่างขั้นต่ำและสูงสุด');
    }

    if ($employmentType === 'AGRICULTURE' && $agricultureDetail === '') {
        throw new RuntimeException('อาชีพเกษตรต้องระบุว่าทำอะไร');
    }
    if ($employmentType !== 'AGRICULTURE') {
        $agricultureDetail = '';
    }

    return [
        'occupation_code' => $occupationCode,
        'occupation_name' => $occupationName,
        'employment_type' => $employmentType,
        'province_name' => $provinceName,
        'avg_income_min' => $incomeMin,
        'avg_income_max' => $incomeMax,
        'avg_income_default' => $incomeDefault,
        'agriculture_detail' => $agricultureDetail,
        'note_text' => $noteText,
    ];
}

/**
 * @return array<int, array<string,mixed>>
 */
function admin_fetch_occupation_rows(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT
            id, record_uid, version_no, is_deleted, occupation_code, occupation_name, employment_type, province_name,
            avg_income_min, avg_income_max, avg_income_default, agriculture_detail, data_json,
            created_by, created_at, updated_by, updated_at
         FROM master_occupation
         WHERE is_latest = 1
         ORDER BY is_deleted ASC, province_name ASC, employment_type ASC, occupation_name ASC"
    );

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $data = json_decode((string)($row['data_json'] ?? ''), true);
        if (!is_array($data)) {
            $data = [];
        }
        $row['note_text'] = trim((string)($data['note_text'] ?? ''));
        $rows[] = $row;
    }

    return $rows;
}
