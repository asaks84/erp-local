// Máscaras dos campos (só aparência; o servidor guarda só os números).

const digitos = (v) => String(v ?? '').replace(/\D/g, '')

export function cpfCnpj(v) {
    const d = digitos(v).slice(0, 14)
    if (d.length <= 11) {
        return d.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2')
    }
    return d.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d{1,2})$/, '$1-$2')
}

/** CNPJ: 00.000.000/0000-00 (empresa é sempre pessoa jurídica) */
export function cnpj(v) {
    return digitos(v).slice(0, 14).replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d{1,2})$/, '$1-$2')
}

export function telefone(v) {
    const d = digitos(v).slice(0, 11)
    if (d.length <= 10) return d.replace(/^(\d{2})(\d)/, '($1) $2').replace(/(\d{4})(\d{1,4})$/, '$1-$2')
    return d.replace(/^(\d{2})(\d)/, '($1) $2').replace(/(\d{5})(\d{1,4})$/, '$1-$2')
}

export function cep(v) {
    return digitos(v).slice(0, 8).replace(/^(\d{5})(\d)/, '$1-$2')
}

export function placa(v) {
    return String(v ?? '').toUpperCase().replace(/[^A-Z0-9-]/g, '').slice(0, 10)
}

/** placa para exibir: ABC1234 → ABC-1234; Mercosul fica junto */
export function placaFormatada(v) {
    const p = String(v ?? '').toUpperCase().replace(/[^A-Z0-9]/g, '')
    return /^[A-Z]{3}\d{4}$/.test(p) ? `${p.slice(0, 3)}-${p.slice(3)}` : p
}
