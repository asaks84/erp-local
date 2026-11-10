<?php

// Catálogo de permissões. O dia a dia (clientes, estadias, lavagens, receber contas,
// gerar e imprimir mensalidades) vale para todos os níveis; estas são as ações restritas.
// O nível fixo (Administrador) tem todas. Rotas que qualquer pessoa logada pode abrir usam "livre".
// Ver docs/08-seguranca-permissoes-lgpd.md.
return [
    'Operação' => [
        'contas_editar' => ['Alterar e excluir contas', 'Valor e vencimento de contas lançadas'],
        'desconto' => ['Dar desconto em pagamentos', null],
        'mensalidade_valor' => ['Mudar valor de mensalidade', 'Inclui a primeira cobrança e cortesias'],
        'excluir_cadastro' => ['Remover clientes e veículos', null],
        'aprovar' => ['Aprovar cadastros da fila de espera', null],
    ],
    'Gestão' => [
        'contas_aberto' => ['Contas em aberto e inadimplência', 'Também os valores no Início'],
        'rel_mensalistas' => ['Relatórios de mensalistas', 'Sem valores de receita'],
        'faturamento' => ['Faturamento', 'Recebimentos, contas recebidas e receita'],
        'extrato' => ['Importar extrato bancário', null],
        'convenios' => ['Configurar convênios', null],
        'contas_pagar' => ['Contas a pagar', 'Lançar e pagar despesas, folha e holerites'],
    ],
    'Sistema' => [
        'configuracoes' => ['Configurações', 'Empresa, unidades, níveis de acesso, preços e serviços'],
        'usuarios' => ['Usuários e registro de atividades', null],
    ],
];
