import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import '../../css/landing.css';
import { BrandMark } from '../components/ui';

const stages = [
    {
        name: 'Recebimento',
        title: 'O balcão registra o que chegou.',
        description: 'Cliente, equipamento, número de série, acessórios e o defeito que o cliente relatou. Esses dados ficam gravados na ordem mesmo que o cadastro mude depois.',
        status: 'Recebida',
        detail: 'Maria Silva, não liga',
        event: 'Equipamento recebido e condições registradas',
    },
    {
        name: 'Diagnóstico',
        title: 'O técnico anota o que encontrou.',
        description: 'O diagnóstico e o técnico responsável ficam na própria ordem. O atendimento lê dali, sem recado em papel.',
        status: 'Em diagnóstico',
        detail: 'Placa sem curto, fonte com defeito',
        event: 'Diagnóstico registrado pelo responsável',
    },
    {
        name: 'Orçamento',
        title: 'O cliente aprova uma versão.',
        description: 'Peças do estoque e serviços, com o preço copiado na hora. Se o orçamento mudar, sai uma versão nova; a aprovação continua presa à versão que o cliente viu.',
        status: 'Aguardando aprovação',
        detail: 'Versão 2, R$ 630,00',
        event: 'Orçamento emitido para aprovação do cliente',
    },
    {
        name: 'Reparo',
        title: 'As peças saem reservadas.',
        description: 'Aprovado o orçamento, as peças ficam reservadas para esta ordem. Quando o reparo começa, o estoque baixa exatamente o que foi reservado.',
        status: 'Em reparo',
        detail: 'Peças reservadas',
        event: 'Reparo iniciado e consumo de peças registrado',
    },
    {
        name: 'Entrega',
        title: 'A entrega abre a garantia.',
        description: 'A entrega fica registrada com data e responsável, e o prazo de garantia começa a contar dali. Se o equipamento voltar, a nova ordem nasce ligada a esta.',
        status: 'Entregue',
        detail: 'Garantia até 27/12/2026',
        event: 'Entrega do equipamento registrada',
    },
];

export default function Landing() {
    const [active, setActive] = useState(0);
    const stage = stages[active];
    useEffect(() => {
        const previous = document.title;
        document.title = 'OficinaFlow, ordens de serviço para assistência técnica';
        return () => {
            document.title = previous;
        };
    }, []);
    return (
        <div className="landing">
            <a className="lp-skip" href="#conteudo">
                Pular para o conteúdo
            </a>
            <header className="lp-header lp-wrap">
                <Link className="lp-brand" to="/" aria-label="OficinaFlow, página inicial">
                    <BrandMark size={32} />
                    OficinaFlow
                </Link>
                <nav aria-label="Navegação principal">
                    <a href="#como-funciona">Como funciona</a>
                    <a href="#recursos">Recursos</a>
                    <a href="#duvidas">Dúvidas</a>
                </nav>
                <Link className="lp-button lp-button-small" to="/login">
                    Entrar no sistema
                </Link>
            </header>
            <main id="conteudo">
                <section className="lp-hero lp-wrap">
                    <div className="lp-hero-copy">
                        <h1>A ordem de serviço que acompanha o equipamento do balcão à entrega.</h1>
                        <p className="lp-lead">
                            Para assistências técnicas de celular, notebook e videogame. Entrada, diagnóstico, orçamento aprovado pelo cliente e peças do estoque na
                            mesma ordem.
                        </p>
                        <div className="lp-actions">
                            <Link className="lp-button" to="/login">
                                Acessar OficinaFlow
                            </Link>
                            <a className="lp-text-link" href="#como-funciona">
                                Como uma ordem anda
                            </a>
                        </div>
                    </div>
                    <div className="lp-hero-photo">
                        <img
                            src="/images/landing/workbench-premium-v1.webp"
                            alt="Bancada organizada de assistência técnica com notebook e ferramentas de precisão"
                            width="1672"
                            height="941"
                            fetchPriority="high"
                        />
                    </div>
                </section>
                <div className="lp-context lp-wrap">
                    <p>Dois perfis: o atendimento cuida do balcão, do orçamento e do estoque; o técnico, do diagnóstico e do reparo.</p>
                </div>
                <section id="como-funciona" className="lp-flow lp-wrap">
                    <div className="lp-section-heading">
                        <h2>Como uma ordem anda na oficina</h2>
                        <p>Escolha uma etapa para ver o que ela registra.</p>
                    </div>
                    <div className="lp-stages" aria-label="Explore as etapas de uma ordem">
                        {stages.map((item, index) => (
                            <button key={item.name} type="button" aria-pressed={active === index} aria-controls="stage-detail" onClick={() => setActive(index)}>
                                <span className="lp-step-number">0{index + 1}</span>
                                {item.name}
                            </button>
                        ))}
                    </div>
                    <div className="lp-flow-content" id="stage-detail">
                        <div className="lp-flow-description" aria-live="polite">
                            <h3>{stage.title}</h3>
                            <p>{stage.description}</p>
                        </div>
                        <div className="lp-order-preview">
                            <div className="lp-preview-top">
                                <span className="lp-preview-tag">#1</span>
                                <span>Exemplo</span>
                            </div>
                            <div className="lp-preview-title">
                                <strong>Dell Inspiron 15</strong>
                                <span className="lp-preview-status">{stage.status}</span>
                            </div>
                            <p>{stage.detail}</p>
                            <div className="lp-preview-event">
                                <span aria-hidden="true">✓</span>
                                <div>
                                    <strong>{stage.name}</strong>
                                    <p>{stage.event}</p>
                                </div>
                            </div>
                            <div className="lp-preview-progress" aria-hidden="true">
                                {stages.map((item, index) => (
                                    <span key={item.name} className={index <= active ? 'is-complete' : ''} />
                                ))}
                            </div>
                        </div>
                    </div>
                </section>
                <section id="recursos" className="lp-resources">
                    <div className="lp-wrap lp-resource-grid">
                        <div className="lp-repair-photo">
                            <img
                                src="/images/landing/repair-detail-premium-v1.webp"
                                alt="Técnico realizando um reparo de precisão em um celular"
                                width="1536"
                                height="1024"
                                loading="lazy"
                            />
                        </div>
                        <div className="lp-resource-copy">
                            <h2>O que fica registrado</h2>
                            <div className="lp-feature">
                                <h3>Orçamento em versões</h3>
                                <p>Cada revisão vira uma versão nova. A decisão do cliente guarda nome, canal e data, e o PDF sai de qualquer versão.</p>
                            </div>
                            <div className="lp-feature">
                                <h3>Estoque que não fica negativo</h3>
                                <p>Saldo, reservado e disponível por peça. Duas pessoas reservando a última unidade ao mesmo tempo não geram saldo negativo.</p>
                            </div>
                            <div className="lp-feature">
                                <h3>Histórico da ordem</h3>
                                <p>Fotos do equipamento, documentos e cada mudança de situação com autor e horário.</p>
                            </div>
                        </div>
                    </div>
                </section>
                <section className="lp-everyday lp-wrap" aria-labelledby="everyday-title">
                    <div className="lp-section-heading">
                        <h2 id="everyday-title">Do balcão à bancada</h2>
                        <p>O que o atendimento registra é o que o técnico lê.</p>
                    </div>
                    <div className="lp-everyday-photos">
                        <figure>
                            <img
                                src="/images/landing/customer-intake-premium-v1.webp"
                                alt="Atendente conversando com um cliente sobre o notebook recebido na assistência"
                                width="1536"
                                height="1024"
                                loading="lazy"
                                decoding="async"
                            />
                            <figcaption>
                                <h3>No balcão</h3>
                                <p>Relato do cliente, acessórios e ordens anteriores do mesmo equipamento.</p>
                            </figcaption>
                        </figure>
                        <figure>
                            <img
                                src="/images/landing/parts-stock-premium-v1.webp"
                                alt="Gaveta de estoque organizada com telas de celular, memórias, cabos e peças de reposição"
                                width="1536"
                                height="1024"
                                loading="lazy"
                                decoding="async"
                            />
                            <figcaption>
                                <h3>No estoque</h3>
                                <p>Cada entrada, reserva e baixa aparece no histórico da peça.</p>
                            </figcaption>
                        </figure>
                    </div>
                </section>
                <section id="duvidas" className="lp-faq lp-wrap">
                    <div>
                        <h2>Perguntas frequentes</h2>
                    </div>
                    <div className="lp-questions">
                        {[
                            [
                                'Para que tipo de assistência o sistema foi pensado?',
                                'Para assistências técnicas que recebem equipamentos, fazem diagnósticos, elaboram orçamentos e utilizam peças nos reparos. O fluxo acompanha cada equipamento até a entrega.',
                            ],
                            [
                                'O atendimento e os técnicos podem usar juntos?',
                                'Sim. O sistema tem perfis de atendimento e técnico, com ações específicas para cada função e acesso às informações da ordem de serviço.',
                            ],
                            [
                                'O cliente aprova pelo sistema?',
                                'A decisão do cliente é registrada pelo atendimento, com nome, canal e data. O orçamento pode ser gerado em PDF e enviado por e-mail. Não há portal de aprovação do cliente.',
                            ],
                            [
                                'Como faço para acessar?',
                                'Use o botão Entrar no sistema com as credenciais da sua conta. O OficinaFlow não oferece cadastro público de contas nesta versão.',
                            ],
                        ].map(([question, answer]) => (
                            <details key={question}>
                                <summary>
                                    {question}
                                    <span aria-hidden="true">+</span>
                                </summary>
                                <p>{answer}</p>
                            </details>
                        ))}
                    </div>
                </section>
                <section className="lp-cta lp-wrap">
                    <div>
                        <h2>Abra a primeira ordem</h2>
                        <p>Entre com a sua conta de atendimento ou de técnico.</p>
                    </div>
                    <Link className="lp-button lp-button-light" to="/login">
                        Entrar no sistema
                    </Link>
                </section>
            </main>
            <footer className="lp-footer lp-wrap">
                <Link className="lp-brand" to="/">
                    <BrandMark size={24} />
                    OficinaFlow
                </Link>
                <a href="#conteudo">Voltar ao topo</a>
            </footer>
        </div>
    );
}
