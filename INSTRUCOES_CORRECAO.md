## Limpeza de itens duplicados em propostas

1. Execute primeiro em modo de simulação para revisar o impacto:
	```powershell
	php cli/fix_proposta_itens_duplicates.php --dry-run
	```
2. Se o relatório estiver ok, rode novamente sem o parâmetro para remover os registros redundantes:
	```powershell
	php cli/fix_proposta_itens_duplicates.php
	```
3. O script mantém o item mais recente (maior ID) para cada combinação `proposta_id` + `produto_id` e exclui os demais, registrando um resumo no console.

> Observação: a API `api/proposta_itens.php` agora atualiza o item existente quando a combinação proposta/produto já foi cadastrada, evitando que novas duplicidades ocorram.

## Bloqueio de edição para fornecedores

- O endpoint `api/fornecedor/proposta.php` passou a validar o status atual: somente propostas com status em branco ou `enviada` podem ser alteradas pelo fornecedor.
- No portal do fornecedor (`pages/fornecedor/cotacao.php`) o botão de edição some automaticamente quando a proposta estiver `aprovada`, `rejeitada` ou `cancelada`, exibindo um aviso de leitura.
- Caso o comprador altere o status no painel interno e o fornecedor ainda esteja com a tela aberta, basta atualizar a página para refletir o bloqueio.
