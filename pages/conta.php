<?php
session_start();
require_once __DIR__.'/../includes/auth.php';
$u = auth_requer_login();
require_once __DIR__.'/../includes/db.php';
$db = get_db_connection();

$mensagem = '';
$erros = [];

// CSRF token
if (empty($_SESSION['csrf_token'])) {
  try { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } catch (Throwable $e) { $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32)); }
}

// Upload avatar dir
$avatarDir = __DIR__.'/../assets/images/avatars';
if(!is_dir($avatarDir)) @mkdir($avatarDir,0775,true);
$avatarWebBase = '../assets/images/avatars/';

// Carregar avatar atual
$hasAvatarColumn = true; // agora garantido pela migração
try { $st=$db->prepare('SELECT avatar_url FROM usuarios WHERE id=?'); $st->execute([$u['id']]); $uAvatar = $st->fetchColumn(); } catch(Throwable $e){ $uAvatar=null; $hasAvatarColumn=false; }

if($_SERVER['REQUEST_METHOD']==='POST'){
    // Verificação CSRF
    $csrfOk = isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
    if(!$csrfOk){ http_response_code(400); echo 'Token CSRF inválido'; exit; }

    $nome = trim($_POST['nome']??'');
    $email = trim($_POST['email']??'');
    $senhaAtual = $_POST['senha_atual']??'';
    $senhaNova = $_POST['senha_nova']??'';
    $senhaConf = $_POST['senha_conf']??'';

    if($nome==='') $erros[]='Nome obrigatório';
    if($email==='') $erros[]='Email obrigatório';

    $novoAvatarPath = null;
    if(isset($_FILES['avatar']) && $_FILES['avatar']['error']!==UPLOAD_ERR_NO_FILE){
        if($_FILES['avatar']['error']!==UPLOAD_ERR_OK){
            $erros[]='Falha no upload do avatar';
        } else {
            $tmp = $_FILES['avatar']['tmp_name'];
            $size = (int)($_FILES['avatar']['size'] ?? 0);
            $ext = strtolower(pathinfo($_FILES['avatar']['name'],PATHINFO_EXTENSION));
            $permitidos = ['png','jpg','jpeg','gif','webp'];
            if($size > 2*1024*1024) $erros[]='Avatar acima de 2MB';
            if(!in_array($ext,$permitidos)) $erros[]='Formato de avatar inválido';
            $imgInfo = @getimagesize($tmp);
            if($imgInfo===false) $erros[]='Arquivo de avatar não é uma imagem válida';
            if(!$erros){
                $fname = 'u'.$u['id'].'_'.time().'.'.$ext;
                if(move_uploaded_file($tmp,$avatarDir.'/'.$fname)){
                    $novoAvatarPath = $avatarWebBase.$fname;
                } else {
                    $erros[]='Não foi possível salvar o avatar';
                }
            }
        }
    }

    $changePass = ($senhaNova!=='' || $senhaConf!=='');
    if($changePass){
        if($senhaNova==='' || $senhaConf==='') $erros[]='Preencha nova senha e confirmação';
        if($senhaNova!==$senhaConf) $erros[]='Confirmação de senha não confere';
        if($senhaAtual==='') $erros[]='Informe a senha atual para alterar';
        else {
            $st = $db->prepare('SELECT senha FROM usuarios WHERE id=?'); $st->execute([$u['id']]); $hash = $st->fetchColumn();
            if(!$hash || !password_verify($senhaAtual,$hash)) $erros[]='Senha atual incorreta';
        }
    }

    if(!$erros){
        $st=$db->prepare('SELECT id FROM usuarios WHERE email=? AND id<>?'); $st->execute([$email,$u['id']]); if($st->fetch()) $erros[]='Email já em uso';
    }

    if(!$erros){
        $db->prepare('UPDATE usuarios SET nome=?, email=? WHERE id=?')->execute([$nome,$email,$u['id']]);
        if($changePass){
            $hash=password_hash($senhaNova,PASSWORD_DEFAULT);
            $db->prepare('UPDATE usuarios SET senha=? WHERE id=?')->execute([$hash,$u['id']]);
            auditoria_log($u['id'],'conta_change_password','usuarios',$u['id'],null);
        }
        if($novoAvatarPath && $hasAvatarColumn){
            $db->prepare('UPDATE usuarios SET avatar_url=? WHERE id=?')->execute([$novoAvatarPath,$u['id']]);
            $uAvatar = $novoAvatarPath;
            $_SESSION['usuario_avatar_url'] = $uAvatar; // atualiza sessão para navbar
        }
        auditoria_log($u['id'],'conta_update','usuarios',$u['id'],['email'=>$email]);
        $mensagem='Dados atualizados com sucesso';
        $_SESSION['usuario_nome']=$nome;
        auth_clear_cache(); // garantir recarregamento
    }
}

?><!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Minha Conta</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="../assets/css/nexus.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
.page-wrap{max-width:1000px;margin:1.6rem auto;padding:0 1.2rem}
.section-card{background:#fff;border:1px solid var(--matrix-border);padding:1.1rem 1.15rem;border-radius:12px;margin-bottom:1.1rem}
.section-card h2{font-size:.8rem;font-weight:700;letter-spacing:.55px;text-transform:uppercase;color:#4b5563;margin:0 0 .8rem}
.field-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem}
.field-grid label.form-label{font-size:.72rem;color:#6b7280;font-weight:600;letter-spacing:.2px;margin-bottom:.25rem}
.avatar-box{display:flex;align-items:center;gap:1rem;margin-bottom:1rem}
.avatar-preview{width:78px;height:78px;border-radius:16px;background:linear-gradient(135deg,var(--matrix-primary),var(--matrix-primary-light));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.6rem;color:#222;overflow:hidden;box-shadow:0 4px 10px rgba(0,0,0,.06)}
.avatar-preview img{width:100%;height:100%;object-fit:cover}
.avatar-filename{display:block;margin-top:.35rem;color:#6b7280;font-size:.72rem;max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
</style>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="page-wrap">
  <h1 class="page-title" style="font-size:24px;margin:0 0 .8rem;font-weight:700">Minha Conta</h1>
  <?php if($mensagem): ?><div class="alert alert-success py-2 small"><?=htmlspecialchars($mensagem)?></div><?php endif; ?>
  <?php if($erros): ?><div class="alert alert-danger py-2 small"><?=implode('<br>',array_map('htmlspecialchars',$erros))?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'] ?? '')?>">

    <div class="section-card">
      <h2>Perfil</h2>
      <div class="avatar-box">
        <div class="avatar-preview" id="avatarPrev">
          <?php if($uAvatar): ?><img src="<?=htmlspecialchars($uAvatar)?>" alt="avatar"><?php else: ?><?=strtoupper(substr($u['nome'],0,1))?><?php endif; ?>
        </div>
        <div>
          <label class="form-label" style="margin-bottom:.2rem">Alterar Avatar</label>
          <input type="file" name="avatar" id="avatarInput" accept="image/png,image/jpeg,image/gif,image/webp" style="display:none">
          <button type="button" class="btn btn-matrix-secondary" id="avatarTrigger"><i class="bi bi-image"></i> <span>Escolher Imagem</span></button>
          <small class="avatar-filename" id="avatarFilename">PNG, JPG, GIF ou WEBP até 2MB</small>
        </div>
      </div>
      <div class="field-grid">
        <div>
          <label class="form-label">Nome</label>
          <input class="form-control" name="nome" value="<?=htmlspecialchars($u['nome'])?>" required>
        </div>
        <div>
          <label class="form-label">Email</label>
          <input class="form-control" type="email" name="email" value="<?=htmlspecialchars($u['email'])?>" required>
        </div>
      </div>
    </div>

    <div class="section-card">
      <h2>Segurança</h2>
      <div class="field-grid">
        <div><label class="form-label">Senha Atual</label><input class="form-control" type="password" name="senha_atual" autocomplete="current-password"></div>
        <div><label class="form-label">Nova Senha</label><input class="form-control" type="password" name="senha_nova" autocomplete="new-password"></div>
        <div><label class="form-label">Confirmar Nova Senha</label><input class="form-control" type="password" name="senha_conf" autocomplete="new-password"></div>
      </div>
      <small class="text-muted" style="display:block;margin-top:.5rem">Preencha os três campos para alterar sua senha.</small>
    </div>

    <div class="mt-3"><button class="btn btn-matrix-primary" type="submit">Salvar Alterações</button></div>
  </form>
</div>
<script>
// Preview avatar + botão estilizado
const fileInput = document.getElementById('avatarInput');
const avatarTrigger = document.getElementById('avatarTrigger');
const filenameLabel = document.getElementById('avatarFilename');
if(avatarTrigger && fileInput){
  avatarTrigger.addEventListener('click',()=> fileInput.click());
  fileInput.addEventListener('change',()=>{
    const f = fileInput.files[0];
    if(!f){ filenameLabel.textContent='Nenhum arquivo selecionado'; return; }
    const url = URL.createObjectURL(f);
    document.getElementById('avatarPrev').innerHTML = '<img src="'+url+'" alt="avatar">';
    filenameLabel.textContent = f.name;
  });
}
</script>
</body>
</html>
