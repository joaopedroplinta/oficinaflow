import { expect, test, type Page } from '@playwright/test';

async function login(page: Page, role = 'admin') {
    await page.goto('/login');
    await page.getByLabel('E-mail', { exact: true }).fill(`browser-${role}@oficinaflow.test`);
    await page.getByLabel('Senha', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Ordens de serviço' })).toBeVisible();
}
async function menu(page: Page) {
    const button = page.getByRole('button', { name: 'Menu', exact: true });
    if ((await button.isVisible()) && (await button.getAttribute('aria-expanded')) === 'false') await button.click();
}
async function noOverflow(page: Page) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
}
async function section(page: Page, name: string) {
    await page.getByRole('navigation', { name: 'Seções da ordem' }).getByRole('link', { name, exact: true }).click();
}

test('página de apresentação: visitante cai nela, navega pelas etapas e chega ao login', async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on('pageerror', (e) => consoleErrors.push(e.message));
    await page.goto('/');
    await expect(page).toHaveTitle('OficinaFlow, ordens de serviço para assistência técnica');
    await expect(page.getByRole('heading', { level: 1, name: /^Da entrada à entrega\.\s*Cada reparo no seu lugar\.$/ })).toBeVisible();
    for (const width of [320, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await noOverflow(page);
    }
    await page.getByRole('button', { name: 'Diagnóstico' }).click();
    await expect(page.getByRole('heading', { name: 'O técnico anota o que encontrou.' })).toBeVisible();
    await page.getByRole('link', { name: 'Acessar OficinaFlow', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByLabel('E-mail', { exact: true })).toBeVisible();
    expect(consoleErrors).toEqual([]);
});

test('recebimento, diagnóstico, orçamento, proteção de rascunho e entrega', async ({ page }, info) => {
    const consoleErrors: string[] = [];
    page.on('pageerror', (e) => consoleErrors.push(e.message));
    await login(page);
    const customer = `Cliente navegador ${info.project.name} ${Date.now()}`;
    await page.getByRole('link', { name: 'Receber equipamento', exact: true }).first().click();
    await page.getByRole('button', { name: 'Cadastrar novo cliente' }).click();
    await page.getByLabel('Nome', { exact: true }).fill(customer);
    await page.getByLabel('Telefone', { exact: true }).fill('41999998888');
    await page.getByRole('button', { name: 'Cadastrar e continuar' }).click();
    await expect(page.getByRole('heading', { name: 'Qual equipamento será recebido?' })).toBeVisible();
    await page.getByRole('button', { name: 'Cadastrar equipamento', exact: true }).click();
    await page.getByLabel('Categoria').fill('Notebook');
    await page.getByLabel('Marca').fill('Dell');
    await page.getByLabel('Modelo').fill('Inspiron de teste');
    await page.getByRole('button', { name: 'Adicionar equipamento' }).click();
    await page.getByLabel('Problema relatado').fill('Equipamento não liga.');
    await page.getByLabel('Condição de entrada').fill('Carcaça sem avarias.');
    await page.getByLabel('Acessórios entregues').fill('Carregador');
    await noOverflow(page);
    await page.getByRole('button', { name: 'Confirmar recebimento e abrir ordem' }).click();
    await expect(page.getByRole('heading', { name: /^Ordem #/ })).toBeVisible();
    const orderUrl = page.url();
    await page.getByRole('button', { name: 'Iniciar diagnóstico', exact: true }).click();
    await page.getByLabel('Diagnóstico', { exact: true }).fill('Limpeza e ajuste do conector.');
    await page.getByLabel('Responsável técnico').selectOption({ label: 'Técnico de teste' });
    await menu(page);
    await page.getByRole('navigation', { name: 'Principal', exact: true }).getByRole('link', { name: 'Clientes' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.getByRole('button', { name: 'Continuar editando' }).click();
    await expect(page.getByLabel('Diagnóstico', { exact: true })).toHaveValue('Limpeza e ajuste do conector.');
    await page.getByRole('button', { name: 'Salvar diagnóstico' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Diagnóstico salvo.' })).toBeVisible();
    await section(page, 'Orçamento');
    await page.getByRole('button', { name: 'Criar orçamento', exact: true }).click();
    await page.getByRole('button', { name: 'Adicionar item' }).click();
    await page.getByLabel('Tipo', { exact: true }).selectOption('service');
    await page.getByLabel('Descrição', { exact: true }).fill('Limpeza e ajuste');
    await page.getByLabel('Preço unitário em reais').fill('125,50');
    await menu(page);
    await page.getByRole('navigation', { name: 'Principal', exact: true }).getByRole('link', { name: 'Ordens', exact: true }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.getByRole('button', { name: 'Continuar editando' }).click();
    // Keep the post-save refresh pending to exercise fast clicks on a slow connection.
    let releaseRefresh!: () => void;
    let refreshStarted!: () => void;
    const pendingRefresh = new Promise<void>((resolve) => {
        releaseRefresh = resolve;
    });
    const started = new Promise<void>((resolve) => {
        refreshStarted = resolve;
    });
    const detailApi = `${new URL(orderUrl).origin}/api/v1${new URL(orderUrl).pathname.replace('/ordens/', '/work-orders/')}`;
    await page.route(detailApi, async (route) => {
        if (route.request().method() === 'GET') {
            refreshStarted();
            await pendingRefresh;
        }
        await route.continue();
    });
    await page.getByRole('button', { name: 'Salvar rascunho' }).click();
    await started;
    try {
        await expect(page.getByRole('button', { name: 'Salvar rascunho' })).toHaveText('Salvar rascunho');
        await expect(page.getByRole('button', { name: 'Emitir orçamento', exact: true })).toBeDisabled();
    } finally {
        releaseRefresh();
    }
    await page.getByRole('button', { name: 'Emitir orçamento', exact: true }).click();
    await page.getByRole('button', { name: 'Emitir agora', exact: true }).click();
    await page.getByLabel('Decisão do cliente').selectOption('approved');
    await page.getByLabel('Nome de quem decidiu').fill(customer);
    await page.getByLabel('Canal', { exact: true }).selectOption('in_person');
    await page.getByRole('button', { name: 'Revisar decisão' }).click();
    await page.getByRole('button', { name: 'Confirmar decisão', exact: true }).click();
    await page.getByRole('button', { name: 'Iniciar reparo', exact: true }).click();
    await page.getByRole('button', { name: 'Iniciar agora', exact: true }).click();
    await page.getByLabel('Trabalho executado').fill('Conector ajustado; teste de funcionamento concluído.');
    await page.getByRole('button', { name: 'Concluir reparo', exact: true }).click();
    await page.getByRole('button', { name: 'Concluir agora', exact: true }).click();
    await page.getByRole('button', { name: 'Entregar equipamento', exact: true }).click();
    await page.getByRole('button', { name: 'Registrar entrega', exact: true }).click();
    await expect(page.locator('.page-heading .status-badge')).toHaveText('Entregue');
    await noOverflow(page);
    await page.screenshot({ path: info.outputPath('ordem-entregue.png'), fullPage: true });
    await section(page, 'Entrada');
    await page.getByRole('link', { name: customer, exact: true }).click();
    await page.getByRole('button', { name: 'Histórico', exact: true }).click();
    await expect(page.locator(`a[href="${new URL(orderUrl).pathname}"]`).filter({ visible: true })).toBeVisible();
    await page.goto('/?scope=closed&sort=oldest');
    await expect(page.getByText(customer, { exact: true }).filter({ visible: true })).toBeVisible();
    await page
        .locator(`a[href="${new URL(orderUrl).pathname}"]`)
        .filter({ visible: true })
        .click();
    await page.getByRole('link', { name: 'Voltar à consulta' }).click();
    await expect(page).toHaveURL(/scope=closed&sort=oldest/);
    await expect(page.getByText(customer, { exact: true }).filter({ visible: true })).toBeVisible();
    await noOverflow(page);
    await page.screenshot({ path: info.outputPath('ordens.png'), fullPage: true });
    expect(consoleErrors).toEqual([]);
});

test('filtros, estoque vazio, navegação por teclado e erros recuperáveis', async ({ page }, info) => {
    await login(page);
    await page.getByText('Buscar e filtrar', { exact: false }).click();
    const viewport = page.viewportSize()!;
    for (const width of [320, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await noOverflow(page);
    }
    await page.setViewportSize(viewport);
    await page.getByLabel('Cliente', { exact: true }).fill('Cliente que não existe');
    await page.getByRole('button', { name: 'Aplicar filtros' }).click();
    await expect(page.getByRole('heading', { name: 'Nenhuma ordem com esses filtros' })).toBeVisible();
    await page.getByRole('button', { name: 'Limpar filtros', exact: true }).first().click();
    await expect(page).toHaveURL('http://127.0.0.1:8012/');
    await noOverflow(page);
    await menu(page);
    await page.getByRole('navigation', { name: 'Principal', exact: true }).getByRole('link', { name: 'Estoque' }).click();
    await page.getByLabel('Sem disponibilidade').check();
    await page.getByRole('button', { name: 'Filtrar', exact: true }).click();
    await expect(page).toHaveURL(/unavailable=1/);
    await noOverflow(page);
    await page.goto('/clientes/novo');
    await page.getByLabel('Nome', { exact: true }).fill('Nome de teste');
    await page.getByLabel('Telefone', { exact: true }).fill('41999998888');
    await page.route('**/api/v1/customers', async (route) => {
        if (route.request().method() === 'POST') await route.fulfill({ status: 422, json: { errors: { phone: ['Informe um telefone válido.'] } } });
        else await route.continue();
    });
    await page.getByRole('button', { name: 'Cadastrar cliente', exact: true }).click();
    await expect(page.getByRole('alert')).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(page.getByRole('button', { name: 'Informe um telefone válido.' })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.getByLabel('Telefone', { exact: true })).toBeFocused();
    await page.screenshot({ path: info.outputPath('validacao.png'), fullPage: true });
});

test('técnico encontra suas ordens e não recebe ações de atendimento', async ({ page }) => {
    await login(page, 'tech');
    await expect(page.getByRole('link', { name: 'Receber equipamento' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Minhas ordens', exact: true }).click();
    await expect(page).toHaveURL(/mine=1/);
    await expect(page.getByRole('button', { name: 'Minhas ordens', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await noOverflow(page);
    await page.goto('/ordens/nova');
    await expect(page.getByText('Só o atendimento abre ordens de serviço.')).toBeVisible();
});
