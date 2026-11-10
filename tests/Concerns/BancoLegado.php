<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/** Banco de teste no formato do sistema atual (tabelas em MAIÚSCULAS, como no servidor). */
trait BancoLegado
{
    protected function criarBancoLegado(): void
    {
        $db = DB::connection('legado');
        $tabelas = [
            'USUARIOS' => 'usr_id SMALLINT AUTO_INCREMENT PRIMARY KEY, usr_full_name VARCHAR(40), usr_nickname VARCHAR(25), usr_password VARCHAR(255), is_online CHAR(1) DEFAULT 0, usr_admin TINYINT(1) DEFAULT 0, usr_niv_id INT NULL',
            'NIVEIS_ACESSO' => 'niv_id INT AUTO_INCREMENT PRIMARY KEY, niv_nome VARCHAR(40), niv_permissoes TEXT, niv_fixo TINYINT(1) DEFAULT 0',
            'LOG_ATIVIDADES' => 'log_id INT AUTO_INCREMENT PRIMARY KEY, log_quando DATETIME, log_usuario VARCHAR(25), log_ip VARCHAR(45), log_acao VARCHAR(40), log_detalhe VARCHAR(255)',
            'CLIENTES' => 'cli_id SMALLINT AUTO_INCREMENT PRIMARY KEY, cli_nome VARCHAR(75), cli_cpf VARCHAR(14) NULL, cli_cadastrado_em DATE NULL, cli_dia_vencimento TINYINT DEFAULT 5, cli_cliend_id SMALLINT, cli_clitel_id SMALLINT',
            'CLIENTES_ENDERECO' => 'cliend_id SMALLINT AUTO_INCREMENT PRIMARY KEY, cliend_logradouro VARCHAR(75), cliend_nr INT, cliend_bairro VARCHAR(60), cliend_cidade VARCHAR(60), cliend_estado VARCHAR(2), cliend_complemento VARCHAR(75) NULL, cliend_cep VARCHAR(8)',
            'CLIENTES_TELEFONE' => 'clitel_id SMALLINT AUTO_INCREMENT PRIMARY KEY, clitel_telefone_fixo VARCHAR(12), clitel_telefone_celular VARCHAR(12)',
            'VEICULOS' => 'vcl_id SMALLINT AUTO_INCREMENT PRIMARY KEY, vcl_placa VARCHAR(11), vcl_cli_id SMALLINT, vcl_vcltp_tipo TINYINT, vcl_modelo VARCHAR(20), vcl_marca VARCHAR(20), vcl_cor VARCHAR(20), vcl_periodo VARCHAR(7)',
            'VEICULOS_MENSALISTA' => 'vclms_id SMALLINT AUTO_INCREMENT PRIMARY KEY, vclms_vcl_placa VARCHAR(11), vclms_mensalidade DECIMAL(5,2)',
            'VEICULOS_TIPO' => 'vcltp_id TINYINT PRIMARY KEY, vcltp_descricao VARCHAR(20), vcltp_detalhe VARCHAR(60) NULL',
            'TARIFAS' => "tar_id INT AUTO_INCREMENT PRIMARY KEY, tar_nome VARCHAR(60), tar_tolerancia SMALLINT DEFAULT 0, tar_fracao SMALLINT DEFAULT 0, tar_restante VARCHAR(10) DEFAULT 'regras', tar_atualizado_em DATETIME NULL",
            'TARIFA_FAIXAS' => "tf_id INT AUTO_INCREMENT PRIMARY KEY, tf_tar_id INT, tf_ordem SMALLINT DEFAULT 0, tf_duracao SMALLINT NULL, tf_modo VARCHAR(5) DEFAULT 'bloco', tf_bloco SMALLINT DEFAULT 60, tf_valor DECIMAL(8,2) DEFAULT 0",
            'TARIFA_PACOTES' => 'tp_id INT AUTO_INCREMENT PRIMARY KEY, tp_tar_id INT, tp_nome VARCHAR(40), tp_duracao INT NULL, tp_valor DECIMAL(8,2) DEFAULT 0, tp_hora_inicio CHAR(5) NULL, tp_hora_fim CHAR(5) NULL',
            'TARIFA_VEICULOS' => 'tv_vcltp_id TINYINT PRIMARY KEY, tv_tar_id INT',
            'SERVICOS' => "srv_codigo VARCHAR(5) PRIMARY KEY, srv_nome_servico VARCHAR(50) NULL, srv_papel VARCHAR(10) DEFAULT 'principal', srv_preco_modo VARCHAR(10) DEFAULT 'fixo', srv_detalhe VARCHAR(80) NULL",
            'SERVICOS_ADICIONAIS' => 'sa_principal VARCHAR(5), sa_adicional VARCHAR(5), PRIMARY KEY (sa_principal, sa_adicional)',
            'SERVICOS_PRECO' => 'srvprc_id SMALLINT AUTO_INCREMENT, srvprc_srv_codigo VARCHAR(5), srvprc_vcltp_tipo TINYINT, srvprc_valor DECIMAL(10,2), PRIMARY KEY (srvprc_srv_codigo, srvprc_vcltp_tipo), KEY (srvprc_id)',
            'CONVENIOS' => "cnv_cli_id INT PRIMARY KEY, cnv_modo VARCHAR(10) DEFAULT 'tudo', cnv_limite_min INT NULL, cnv_desc_tipo VARCHAR(5) NULL, cnv_desc_valor DECIMAL(10,2) NULL, cnv_estadia TINYINT(1) DEFAULT 1, cnv_servicos TINYINT(1) DEFAULT 0, cnv_obs VARCHAR(255) NULL, cnv_ativo TINYINT(1) DEFAULT 1, cnv_atualizado DATETIME NULL, cnv_usuario VARCHAR(50) NULL",
            'CADASTROS_PENDENTES' => 'cp_id INT AUTO_INCREMENT PRIMARY KEY, cp_recebido_em DATETIME, cp_ip VARCHAR(45), cp_consentimento DATETIME, cp_nome VARCHAR(75), cp_cpf VARCHAR(14) NULL, cp_telefone VARCHAR(12) NULL, cp_celular VARCHAR(12) NULL, cp_cep VARCHAR(8), cp_logradouro VARCHAR(75), cp_numero INT, cp_complemento VARCHAR(75) NULL, cp_bairro VARCHAR(60), cp_cidade VARCHAR(60), cp_estado VARCHAR(2), cp_placa VARCHAR(11) NULL, cp_modelo VARCHAR(20) NULL, cp_marca VARCHAR(20) NULL, cp_cor VARCHAR(20) NULL, cp_tipo TINYINT NULL',
            'PAGADORES_VINCULADOS' => "pv_id INT AUTO_INCREMENT PRIMARY KEY, pv_nome VARCHAR(100), pv_documento VARCHAR(14) DEFAULT '', pv_conta VARCHAR(60) NULL, pv_cli_id INT, pv_criado_em DATETIME, pv_usuario VARCHAR(25) NULL",
            'ORDEM_SERVICOS' => 'ord_id INT AUTO_INCREMENT PRIMARY KEY, ord_cli_id INT, ord_vcl_placa VARCHAR(11) NULL, ord_srv_codigo VARCHAR(5), ord_dt_geracao DATE NULL, ord_dt_vencimento DATE NULL, ord_valor DECIMAL(6,2), ord_dt_pagamento DATE NULL',
        ];
        foreach ($tabelas as $nome => $colunas) {
            $db->statement("DROP TABLE IF EXISTS `{$nome}`");
            // latin1 como no sistema atual
            $db->statement("CREATE TABLE `{$nome}` ({$colunas}) DEFAULT CHARSET=latin1");
        }
    }
}
