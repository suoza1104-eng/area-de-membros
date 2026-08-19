# PWA e notificações — configuração de teste

## 1. Criar o projeto Firebase

1. No Firebase Console, crie ou selecione um projeto.
2. Em **Configurações do projeto > Geral**, adicione um aplicativo Web.
3. Copie os valores de `firebaseConfig` para **Admin > Notificações do App**.
4. Em **Cloud Messaging > Configuração da Web**, gere um par de chaves Web Push e copie a chave pública VAPID.
5. Em **Contas de serviço**, gere uma nova chave privada em JSON.
6. Cole o JSON no painel uma única vez. O segredo não volta a ser exibido.

O domínio de produção precisa continuar usando HTTPS. A conta de serviço e sua chave privada nunca devem ser colocadas em JavaScript, no service worker ou no Git.

## 2. Ativar o telefone de teste

1. Entre normalmente como aluno no Android usando Google Chrome.
2. Abra `/area_membros/public/aplicativo.php`.
3. Pelo menu do Chrome, escolha **Instalar app** ou **Adicionar à tela inicial**.
4. Abra o ícone instalado.
5. Volte à URL de teste e toque em **Ativar notificações neste telefone**.

Não existe botão de instalação nas telas normais dos alunos nesta etapa.

## 3. Enviar o teste

1. Abra **Admin > Notificações do App**.
2. Confirme que o telefone aparece em **Dispositivos** com permissão `granted`.
3. Selecione o dispositivo, escreva título e mensagem e clique em **Enviar teste**.
4. Feche ou coloque o aplicativo em segundo plano para validar a notificação de fundo.
5. Toque na notificação para validar o registro de clique.

## Leitura dos indicadores

- **Dispositivos conectados:** instalações/navegadores identificados pelo sistema.
- **Instalaram o app:** abriram a área em modo instalado (`standalone`).
- **Tokens excluídos/inativos:** o Firebase rejeitou o token em uma tentativa de envio. O Android não comunica diretamente a desinstalação de uma PWA.
- **Acesso nas últimas 24h:** abriram uma página que contém o runtime da PWA nesse intervalo.
- **Recebendo notificações:** possuem token ativo e permissão concedida.

“Aceita pelo Firebase” confirma que a infraestrutura aceitou o envio, não que o usuário visualizou a mensagem. O clique é uma confirmação objetiva de interação.
