<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
session_start();
require_once __DIR__.'/includes/db_connection.php';

$msg="";
if(isset($_SESSION['user_id'])){
    header("Location: views/dashboard.php");
    exit();
}

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['login'])){
    $username=trim($_POST['username']??'');
    $password=$_POST['password']??'';

    if($username===''||$password===''){
        $msg="<div class='alert alert-danger'>Por favor ingresa usuario y contraseña.</div>";
    }else{
        $stmt=$conn->prepare("SELECT id,username,password,role FROM users WHERE username=? LIMIT 1");
        $stmt->bind_param("s",$username);
        $stmt->execute();
        $res=$stmt->get_result();
        $user=$res->fetch_assoc();
        $stmt->close();

        if($user && password_verify($password,$user['password'])){
            $_SESSION['user_id']=$user['id'];
            $_SESSION['user']=$user['username'];
            $_SESSION['role']=$user['role'];
            header("Location: views/dashboard.php"); exit;
        }else{
            $msg="<div class='alert alert-danger'>Usuario o contraseña incorrectos.</div>";
        }
    }
}
?>
<!DOCTYPE html><html><head>
<meta charset='UTF-8'><title>Login</title>
<link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css'>
</head><body class='bg-light'>
<div class='container mt-5'><div class='row justify-content-center'><div class='col-md-4'>
<h3 class='mb-4 text-center'>Defectives Inventory</h3>
<?php if($msg) echo $msg; ?>
<div class='card'><div class='card-body'>
<form method='post'>
<div class='mb-3'><label class='form-label'>Usuario</label>
<input type='text' name='username' class='form-control' required></div>
<div class='mb-3'><label class='form-label'>Contraseña</label>
<input type='password' name='password' class='form-control' required></div>
<button type='submit' name='login' class='btn btn-primary w-100'>Ingresar</button>
</form></div></div>
</div></div></div>
</body></html>
