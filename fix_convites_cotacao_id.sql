-- Adiciona vínculo direto entre convites e cotações.
-- Execute uma única vez no banco de dados (ajuste o prefixo se necessário).

ALTER TABLE cotacao_convites
    ADD COLUMN cotacao_id INT NULL AFTER requisicao_id,
    ADD INDEX idx_cotacao_convites_cotacao (cotacao_id),
    ADD CONSTRAINT fk_convites_cotacao FOREIGN KEY (cotacao_id)
        REFERENCES cotacoes(id) ON DELETE SET NULL ON UPDATE CASCADE;
