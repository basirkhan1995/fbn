
<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

require_once "../db.php";
$db = new Database();
$conn = $db->getConnection();
$request_method = $_SERVER["REQUEST_METHOD"];

switch ($request_method) {
    case 'GET':
        get_comProfile();
        break;
    case 'POST':
        upload_comProfile();
        break;
    case 'PUT':
        update_comProfile();
        break;
    default:
        http_response_code(405);
        echo json_encode(array("message" => "Method Not Allowed"));
        break;
}
function get_comProfile() {
    global $conn;
    try {
        $sql = "select comID, comName, comLicenseNo, comSlogan, comDetails, comPhone, comEmail, comWebsite, comLogo, concat(perName, ' ', perLastName) as comOwner, comAddress, comFB, comInsta, comWhatsapp,
                comVerify, comLocalCcy, comTimeZone, comEntryDate, addName, addCity, addProvince, addCountry, addZipCode  from companyProfile
                left join address on address.addID = companyProfile.comAddress
                join personal on personal.perID = companyProfile.comOwner";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
      foreach ($data as &$row) {
    if (!empty($row['comLogo'])) {
        $row['comLogo'] = base64_encode($row['comLogo']);
    } else {
        $row['comLogo'] = null;
    }
}
        echo json_encode($data, JSON_PRETTY_PRINT);
    } 
    catch (PDOException $th) {
        echo json_encode([
            "msg" => "failed",
            "error" => $th->getMessage(),
            "line" => $th->getLine(),
            "file" => $th->getFile()
        ]);
    }
}

function update_comProfile(){
    global $conn;

    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    $data = json_decode(file_get_contents("php://input"), true);

    $id = $data['comID'];
    $licenseNo = $data['comLicenseNo'];
    $slogan = $data['comSlogan'];
    $details = $data['comDetails'];
    $phone = $data['comPhone'];
    $email = $data['comEmail'];
    $website = $data['comWebsite'];
    $address = $data['comAddress'];
    $fb = $data['comFB'];
    $ig = $data['comInsta'];
    $ws = $data['comWhatsapp'];
    $addID = $data['comAddress'];
    $addName = $data['addName'];
    $city = $data['addCity'];
    $province = $data['addProvince'];
    $country = $data['addCountry'];
    $zipCode = $data['addZipCode'];

    try {
        $conn->beginTransaction();

        $stmt = $conn->prepare("UPDATE companyProfile SET
        comLicenseNo=?, comSlogan=?, comDetails=?, comPhone=?, comEmail=?, comWebsite=?, comFB=?, comInsta=?, comWhatsapp=? WHERE comID=?");
        $stmt->execute([$licenseNo, $slogan, $details, $phone, $email, $website, $fb, $ig, $ws, $id]);

        $stmt1 = $conn->prepare("UPDATE address SET
        addName=?, addCity=?, addProvince=?, addCountry=?, addZipCode=? WHERE addID=?");
        $stmt1->execute([$addName, $city, $province, $country, $zipCode, $address]);

        $conn->commit();
        echo json_encode(["msg" => "success", "company" => $id]);
    } catch (\Throwable $th) {
        $conn->rollBack();
        echo json_encode([
            "msg" => "failed",
            "error" => $th->getMessage(),
            "line" => $th->getLine(),
            "file" => $th->getFile(),
            "trace" => $th->getTrace()
        ]);
    }
}

function upload_comProfile(){
    global $conn;

    // 1) Make sure a file actually arrived
    if (!isset($_FILES['image'])) {
        http_response_code(400);
        echo json_encode([
            "msg" => "failed",
            "error" => "No 'image' field in request. Check FormData key.",
            "files" => $_FILES,
        ]);
        return;
    }
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode([
            "msg" => "failed",
            "error" => "Upload error code: " . $_FILES['image']['error'],
        ]);
        return;
    }
    if ($_FILES['image']['size'] === 0) {
        http_response_code(400);
        echo json_encode([
            "msg" => "failed",
            "error" => "Uploaded file is empty (0 bytes).",
        ]);
        return;
    }

    $logo = file_get_contents($_FILES['image']['tmp_name']);
    if ($logo === false || strlen($logo) === 0) {
        http_response_code(500);
        echo json_encode([
            "msg" => "failed",
            "error" => "Could not read uploaded file contents.",
        ]);
        return;
    }

    try {
        $conn->beginTransaction();

        // Single company → LIMIT 1 is fine
        $stmt = $conn->prepare("UPDATE companyProfile SET comLogo = ? ORDER BY comID ASC LIMIT 1");
        $stmt->execute([$logo]);

        $conn->commit();

        echo json_encode([
            "msg"     => "success",
            "bytes"   => strlen($logo),
            "mime"    => $_FILES['image']['type'] ?? null,
        ]);

    } catch (\Throwable $th) {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ]);
    }
}
?>