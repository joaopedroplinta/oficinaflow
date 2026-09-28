import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import '../../css/landing.css';

const stages = [
    {
        name: 'Recebimento',
        title: 'Tudo começa com uma entrada bem feita.',
        description: 'Cadastre o cliente, o equipamento, os acessórios e o relato do problema. A ordem já nasce com as informações de que a equipe precisa.',
        status: 'Recebida',
        detail: 'Notebook • Não liga',
        event: 'Equipamento recebido e condições registradas',
    },
    {
        name: 'Diagnóstico',
        title: 'O diagnóstico encontra o seu lugar.',
        description: 'Registre a avaliação e o técnico responsável na própria ordem. O atendimento acompanha o que foi identificado sem depender de recados.',
        status: 'Em diagnóstico',
        detail: 'Avaliação técnica',
        event: 'Diagnóstico registrado pelo responsável',
    },
    {
        name: 'Orçamento',
        title: 'Cada aprovação, no orçamento certo.',
        description: 'Reúna peças e serviços, envie o orçamento e registre a decisão do cliente. Se algo mudar, as versões anteriores continuam no histórico.',
        status: 'Aguardando aprovação',
        detail: 'Peças e serviços • Versão 2',
        event: 'Orçamento emitido para aprovação do cliente',
    },
    {
        name: 'Reparo',
        title: 'Peças e execução no mesmo ritmo.',
        description: 'Confira a disponibilidade, reserve as peças e acompanhe a execução. O estoque acompanha o que é utilizado no reparo.',
        status: 'Em reparo',
        detail: 'Peças reservadas',
        event: 'Reparo iniciado e consumo de peças registrado',
    },
    {
        name: 'Entrega',
        title: 'Serviço entregue. Histórico preservado.',
        description: 'Registre o trabalho realizado e a entrega do equipamento. Diagnóstico, decisões e movimentações ficam reunidos para futuras consultas.',
        status: 'Entregue',
        detail: 'Serviço concluído',
        event: 'Entrega do equipamento registrada',
    },
];

export default function Landing() {
    const [active, setActive] = useState(0);
    const stage = stages[active];
    useEffect(() => {
        const previous = document.title;
        document.title = 'OficinaFlow — Sua assistência em ordem';
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
                <Link className="lp-brand" to="/apresentacao" aria-label="OficinaFlow, página inicial">
                    <span className="lp-mark" aria-hidden="true">
                        of
                    </span>
                    OficinaFlow<span className="lp-brand-dot">.</span>
                </Link>
                <nav aria-label="Navegação principal">
                    <a href="#como-funciona">Como funciona</a>
                    <a href="#recursos">Recursos</a>
                    <a href="#duvidas">Dúvidas</a>
                </nav>
                <Link className="lp-button lp-button-small" to="/login">
                    Entrar no sistema <span aria-hidden="true">↗</span>
                </Link>
            </header>
            <main id="conteudo">
                <section className="lp-hero lp-wrap">
                    <div className="lp-hero-copy">
                        <p className="lp-kicker">
                            <span aria-hidden="true" /> Gestão para assistências técnicas
                        </p>
                        <h1>
                            Seu foco no reparo.
                            <br />
                            Sua assistência em ordem.
                        </h1>
                        <p className="lp-lead">Do primeiro atendimento à entrega, conecte ordens de serviço, orçamentos e estoque em um só lugar.</p>
                        <div className="lp-actions">
                            <Link className="lp-button" to="/login">
                                Acessar OficinaFlow <span aria-hidden="true">↗</span>
                            </Link>
                            <a className="lp-text-link" href="#como-funciona">
                                Conhecer o fluxo <span aria-hidden="true">↓</span>
                            </a>
                        </div>
                        <p className="lp-hero-note">Menos informação espalhada. Mais clareza para a equipe.</p>
                    </div>
                    <div className="lp-hero-photo">
                        <img
                            src="/images/landing/workbench-premium-v1.webp"
                            alt="Bancada organizada de assistência técnica com notebook e ferramentas de precisão"
                            width="1672"
                            height="941"
                            fetchPriority="high"
                        />
                        <div className="lp-photo-caption">
                            <span>Organização em cada etapa.</span>
                            <span>Precisão em cada reparo.</span>
                        </div>
                    </div>
                </section>
                <div className="lp-context lp-wrap">
                    <p>Feito para a rotina de quem cuida de equipamentos.</p>
                    <div>
                        <span>Atendimento</span>
                        <span>Equipe técnica</span>
                        <span>Controle de estoque</span>
                    </div>
                </div>
                <section id="como-funciona" className="lp-flow lp-wrap">
                    <div className="lp-section-heading">
                        <h2>
                            Um caminho claro.
                            <br />
                            Da entrada à entrega.
                        </h2>
                        <p>Cada ordem conta uma história. Acompanhe o próximo passo sem perder o que já aconteceu.</p>
                    </div>
                    <div className="lp-stages" aria-label="Explore as etapas de uma ordem">
                        {stages.map((item, index) => (
                            <button key={item.name} type="button" aria-pressed={active === index} aria-controls="stage-detail" onClick={() => setActive(index)}>
                                <span className="lp-step-number">0{index + 1}</span>
                                {item.name}
                                <span aria-hidden="true" className="lp-step-arrow">
                                    ↗
                                </span>
                            </button>
                        ))}
                    </div>
                    <div className="lp-flow-content" id="stage-detail">
                        <div className="lp-flow-description" aria-live="polite">
                            <h3>{stage.title}</h3>
                            <p>{stage.description}</p>
                            <a className="lp-text-link" href="#recursos">
                                Veja o que faz parte <span aria-hidden="true">↓</span>
                            </a>
                        </div>
                        <div className="lp-order-preview">
                            <div className="lp-preview-top">
                                <span>Ordem de serviço</span>
                                <span>Exemplo ilustrativo</span>
                            </div>
                            <div className="lp-preview-title">
                                <strong>Notebook em boas mãos.</strong>
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
                            <p>
                                Você cuida dos detalhes.
                                <br />O OficinaFlow conecta o restante.
                            </p>
                        </div>
                        <div className="lp-resource-copy">
                            <h2>
                                A operação inteira.
                                <br />
                                No mesmo lugar.
                            </h2>
                            <div className="lp-feature">
                                <h3>Orçamentos com histórico</h3>
                                <p>Peças, serviços e decisões do cliente organizados por versão. Consulte o que foi aprovado e quando.</p>
                            </div>
                            <div className="lp-feature">
                                <h3>Estoque ligado ao serviço</h3>
                                <p>Acompanhe saldos, reservas e consumo de peças. Saiba o que está disponível e o que já tem destino.</p>
                            </div>
                            <div className="lp-feature">
                                <h3>Informação que acompanha a equipe</h3>
                                <p>Clientes, equipamentos, fotos e linha do tempo reunidos na ordem. Atendimento e técnico com o mesmo contexto.</p>
                            </div>
                        </div>
                    </div>
                </section>
                <section className="lp-everyday lp-wrap" aria-labelledby="everyday-title">
                    <div className="lp-section-heading">
                        <h2 id="everyday-title">
                            Cuidado com o cliente.
                            <br />
                            Controle nos bastidores.
                        </h2>
                        <p>Uma assistência organizada conecta o que acontece no balcão ao que a equipe precisa na bancada.</p>
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
                                <h3>Um atendimento com contexto.</h3>
                                <p>Relato, equipamento e histórico do cliente reunidos desde a entrada.</p>
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
                                <h3>Cada peça com seu lugar.</h3>
                                <p>Disponibilidade e reservas acompanhadas junto às ordens de serviço.</p>
                            </figcaption>
                        </figure>
                    </div>
                </section>
                <section id="duvidas" className="lp-faq lp-wrap">
                    <div>
                        <h2>Antes de começar.</h2>
                        <p>Algumas respostas para conhecer melhor o OficinaFlow.</p>
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
                        <h2>
                            Mais clareza na bancada.
                            <br />
                            Mais controle na gestão.
                        </h2>
                        <p>Sua próxima ordem de serviço começa aqui.</p>
                    </div>
                    <Link className="lp-button lp-button-light" to="/login">
                        Entrar no sistema <span aria-hidden="true">↗</span>
                    </Link>
                </section>
            </main>
            <footer className="lp-footer lp-wrap">
                <Link className="lp-brand" to="/apresentacao">
                    OficinaFlow<span className="lp-brand-dot">.</span>
                </Link>
                <p>Da entrada à entrega, tudo conectado.</p>
                <a href="#conteudo">Voltar ao início ↑</a>
            </footer>
        </div>
    );
}
