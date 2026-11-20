<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

require_once "../db.php";
require_once "../functions.php";

$db = new Database();
$conn = $db->getConnection();
$value = new DataValues($conn);

$data = json_decode(file_get_contents("php://input"), true);
$request_method = $_SERVER["REQUEST_METHOD"];

switch ($request_method) {

case 'GET':
    get_balanceSheet();
break;

default:
    http_response_code(405);

    echo json_encode([
        "message"=>"Method Not Allowed"
    ]);
break;

}

function get_balanceSheet(){

global $conn,$value;

$localCcy =
$value->getCompanyAttributes("comLocalCcy");

$branch =
$_GET['branch'] ?? null;

try{

$rateExpr = "

(
CASE

WHEN td.trdNarration LIKE '%@Rate:%'

THEN

(

td.trdAmount

/

NULLIF(

CAST(

SUBSTRING_INDEX(

TRIM(

SUBSTRING_INDEX(
td.trdNarration,
'@Rate:',
-1
)

),

' ',

1

)

AS DECIMAL(18,8)

),

0

)

)

ELSE

(

td.trdAmount
*
getRate(td.trdCcy,:lCcy)

)

END

)

";

$stmt1 =
$conn->prepare(
"
SELECT *
FROM accountCategory
WHERE acgCategory=?
AND acgID!=8
"
);



$sql2 = "
SELECT

ac.accName,
td.trdAccount,
ag.acgName,
:branch trdBranch,

SUM(
CASE
WHEN tr.trnStatus=1
THEN
CASE
WHEN td.trdDrCr='Dr'
THEN {$rateExpr}
ELSE -({$rateExpr})
END
ELSE 0
END
) current_year,

SUM(
CASE
WHEN
td.trdEntryDate<=MAKEDATE(YEAR(CURDATE())-1,365)
AND tr.trnStatus=1
THEN
CASE
WHEN td.trdDrCr='Dr'
THEN {$rateExpr}
ELSE -({$rateExpr})
END
ELSE 0
END
) last_year

FROM trnDetails td

LEFT JOIN accounts ac
ON ac.accNumber=td.trdAccount

JOIN accountCategory ag
ON ag.acgID=ac.accCategory

JOIN transactions tr
ON tr.trnReference=td.trdReference
";



$sql3 = "
SELECT

ac.accName,
td.trdAccount,
ag.acgName,
:branch trdBranch,

SUM(
CASE
WHEN tr.trnStatus=1
THEN
CASE
WHEN td.trdDrCr='Cr'
THEN {$rateExpr}
ELSE -({$rateExpr})
END
ELSE 0
END
) current_year,

SUM(
CASE
WHEN
td.trdEntryDate<=MAKEDATE(YEAR(CURDATE())-1,365)
AND tr.trnStatus=1
THEN
CASE
WHEN td.trdDrCr='Cr'
THEN {$rateExpr}
ELSE -({$rateExpr})
END
ELSE 0
END
) last_year

FROM trnDetails td

LEFT JOIN accounts ac
ON ac.accNumber=td.trdAccount

JOIN accountCategory ag
ON ag.acgID=ac.accCategory

JOIN transactions tr
ON tr.trnReference=td.trdReference
";

// SQL for Stakeholders with details
$sql4 = "
SELECT
    ac.accName,
    td.trdAccount,
    ag.acgName,
    :branch trdBranch,
    ad.actSignatory,
    CONCAT(p.perName, ' ', p.perLastName) AS stakeholder_name,
    p.perPhone AS stakeholder_phone,
    td.trdCcy AS currency,
    SUM(
        CASE
            WHEN tr.trnStatus=1
            THEN
                CASE
                    WHEN td.trdDrCr='Cr'
                    THEN {$rateExpr}
                    ELSE -({$rateExpr})
                END
            ELSE 0
        END
    ) current_year,
    SUM(
        CASE
            WHEN
            td.trdEntryDate<=MAKEDATE(YEAR(CURDATE())-1,365)
            AND tr.trnStatus=1
            THEN
                CASE
                    WHEN td.trdDrCr='Cr'
                    THEN {$rateExpr}
                    ELSE -({$rateExpr})
                END
            ELSE 0
        END
    ) last_year
FROM trnDetails td
LEFT JOIN accounts ac ON ac.accNumber=td.trdAccount
JOIN accountCategory ag ON ag.acgID=ac.accCategory
JOIN transactions tr ON tr.trnReference=td.trdReference
LEFT JOIN accountDetails ad ON ad.actAccount = ac.accNumber
LEFT JOIN personal p ON p.perID = ad.actSignatory
";



$sql5 = "
SELECT

'Net Profit' accName,
'30000000' trdAccount,
'Profit & Loss' acgName,
:branch trdBranch,

SUM(
CASE
WHEN tr.trnStatus=1
THEN
CASE
WHEN td.trdDrCr='Cr'
THEN {$rateExpr}
ELSE -({$rateExpr})
END
ELSE 0
END
) current_year,

SUM(
CASE
WHEN
td.trdEntryDate<=MAKEDATE(YEAR(CURDATE())-1,365)
AND tr.trnStatus=1
THEN
CASE
WHEN td.trdDrCr='Cr'
THEN {$rateExpr}
ELSE -({$rateExpr})
END
ELSE 0
END
) last_year

FROM trnDetails td

LEFT JOIN accounts ac
ON ac.accNumber=td.trdAccount

JOIN accountCategory ag
ON ag.acgID=ac.accCategory

JOIN transactions tr
ON tr.trnReference=td.trdReference
";



if(empty($branch)){

$sql2 .= "
WHERE ac.accCategory=:cat
AND ac.accCategory!=8
GROUP BY
ac.accName,
td.trdAccount,
ag.acgName
";

$sql3 .= "
WHERE ac.accCategory=:cat
AND ac.accCategory!=8
GROUP BY
ac.accName,
td.trdAccount,
ag.acgName
";

$sql4 .= "
WHERE ac.accCategory=8
GROUP BY
ac.accName,
td.trdAccount,
ag.acgName,
ad.actSignatory,
p.perName,
p.perLastName,
p.perPhone,
td.trdCcy
";

$sql5 .= "
WHERE ag.acgCategory BETWEEN 3 AND 4
";

$branch="All";

}else{

$sql2 .= "
WHERE ac.accCategory=:cat
AND ac.accCategory!=8
AND td.trdBranch=:branch
GROUP BY
ac.accName,
td.trdAccount,
ag.acgName,
td.trdBranch
";

$sql3 .= "
WHERE ac.accCategory=:cat
AND ac.accCategory!=8
AND td.trdBranch=:branch
GROUP BY
ac.accName,
td.trdAccount,
ag.acgName,
td.trdBranch
";

$sql4 .= "
WHERE ac.accCategory=8
AND td.trdBranch=:branch
GROUP BY
ac.accName,
td.trdAccount,
ag.acgName,
ad.actSignatory,
p.perName,
p.perLastName,
p.perPhone,
td.trdCcy,
td.trdBranch
";

$sql5 .= "
WHERE ag.acgCategory BETWEEN 3 AND 4
AND td.trdBranch=:branch
GROUP BY td.trdBranch
";

}

$stmt2=$conn->prepare($sql2);
$stmt3=$conn->prepare($sql3);
$stmt4=$conn->prepare($sql4);
$stmt5=$conn->prepare($sql5);

// ============================================
// 1. GET ASSET BALANCES
// ============================================
$stmt1->execute([1]);

$asset =
$stmt1->fetchAll(PDO::FETCH_ASSOC);

$assetBalances=[];

foreach($asset as $cat){

$stmt2->execute([
':branch'=>$branch,
':lCcy'=>$localCcy,
':cat'=>$cat['acgID']
]);

$assetBalances[
$cat['acgName']
]
=
$stmt2->fetchAll(PDO::FETCH_ASSOC);

}

// ============================================
// 2. GET STAKEHOLDER BALANCES AND CALCULATE AR/AP
// ============================================
$stmt4->execute([
':branch'=>$branch,
':lCcy'=>$localCcy
]);

$stakeholders = $stmt4->fetchAll(PDO::FETCH_ASSOC);

$totalReceivable = 0;
$totalPayable = 0;
$receivableDetails = [];
$payableDetails = [];

foreach($stakeholders as $stakeholder){
    $balance = $stakeholder['current_year'];
    
    if($balance < 0){
        // Customer owes us - Receivable
        $amount = abs($balance);
        $totalReceivable += $amount;
        $stakeholder['current_year'] = $amount;
        $stakeholder['balance_type'] = 'Receivable';
        $receivableDetails[] = $stakeholder;
    } else {
        // We owe them - Payable
        $totalPayable += $balance;
        $stakeholder['balance_type'] = 'Payable';
        $payableDetails[] = $stakeholder;
    }
}

// ============================================
// 3. ADD ACCOUNTS RECEIVABLE (10101013) TO ASSETS
// ============================================
if($totalReceivable > 0){
    if(!isset($assetBalances['Current Asset'])){
        $assetBalances['Current Asset'] = [];
    }
    
    $assetBalances['Current Asset'][] = [
        'accName' => 'Accounts Receivable',
        'trdAccount' => 10101013,
        'acgName' => 'Current Asset',
        'trdBranch' => $branch,
        'current_year' => number_format($totalReceivable, 4, '.', ''),
        'last_year' => '0.0000'
    ];
}

$data['Assets'] = $assetBalances;

// ============================================
// 4. GET LIABILITY BALANCES
// ============================================
$stmt1->execute([2]);

$liability =
$stmt1->fetchAll(PDO::FETCH_ASSOC);

$liabilityBalances=[];

foreach($liability as $cat){

$stmt3->execute([
':branch'=>$branch,
':lCcy'=>$localCcy,
':cat'=>$cat['acgID']
]);

$liabilityBalances[
$cat['acgName']
]
=
$stmt3->fetchAll(PDO::FETCH_ASSOC);

}

// ============================================
// 5. ADD ACCOUNTS PAYABLE (20202020) TO LIABILITIES
// ============================================
if($totalPayable > 0){
    if(!isset($liabilityBalances['Current Liability'])){
        $liabilityBalances['Current Liability'] = [];
    }
    
    $liabilityBalances['Current Liability'][] = [
        'accName' => 'Accounts Payable',
        'trdAccount' => 20202020,
        'acgName' => 'Current Liability',
        'trdBranch' => $branch,
        'current_year' => number_format($totalPayable, 4, '.', ''),
        'last_year' => '0.0000'
    ];
}

$data['Liability'] = $liabilityBalances;

// ============================================
// 6. ADD STAKEHOLDERS WITH DETAILS
// ============================================
$data['Liability']['Stakeholders'] = $stakeholders;

// ============================================
// 7. NET PROFIT
// ============================================
$stmt5->execute([
':branch'=>$branch,
':lCcy'=>$localCcy
]);

$data['Liability']['Net Profit'] = $stmt5->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(
$data,
JSON_PRETTY_PRINT
);

}

catch(PDOException $th){

echo json_encode([

"msg"=>"failed",
"error"=>$th->getMessage(),
"line"=>$th->getLine(),
"file"=>$th->getFile()

]);

}

}

?>