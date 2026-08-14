export type TestProfile = { code: string; label: string; email: string };
export const password = process.env.E2E_PASSWORD ?? 'Password-Test-123!';
export const profiles: TestProfile[] = [
    { code: 'super_admin', label: 'Super Admin', email: 'super.admin.e2e@example.test' },
    { code: 'admin_fonctionnel', label: 'Administrateur fonctionnel', email: 'admin.fonctionnel.e2e@example.test' },
    { code: 'auditeur', label: 'Auditeur', email: 'auditeur.e2e@example.test' },
    { code: 'dg', label: 'Directeur Général', email: 'dg.e2e@example.test' },
    { code: 'planification', label: 'Planification', email: 'planification.e2e@example.test' },
    { code: 'chef_planification', label: 'Chef planification', email: 'chef.planification.e2e@example.test' },
    { code: 'cabinet', label: 'Cabinet DG', email: 'cabinet.e2e@example.test' },
    { code: 'chef_unite_cabinet', label: 'Chef unité Cabinet', email: 'chef.unite.cabinet.e2e@example.test' },
    { code: 'dga_supervision', label: 'Supervision DGA', email: 'dga.supervision.e2e@example.test' },
    { code: 'chef_unite_dga', label: 'Chef unité DGA', email: 'chef.unite.dga.e2e@example.test' },
    { code: 'chef_unite_ucas', label: 'Chef unité UCAS', email: 'chef.unite.ucas.e2e@example.test' },
    { code: 'ucas', label: 'UCAS', email: 'ucas.e2e@example.test' },
    { code: 'sciq', label: 'SCIQ', email: 'sciq.e2e@example.test' },
    { code: 'chef_unite_sciq', label: 'Chef unité SCIQ', email: 'chef.unite.sciq.e2e@example.test' },
    { code: 'direction', label: 'Directeur de direction', email: 'directeur.e2e@example.test' },
    { code: 'service', label: 'Chef de service', email: 'chef.service.e2e@example.test' },
    { code: 'agent', label: 'Agent', email: 'agent.e2e@example.test' },
];
export const credentials = {
    sciq: { email: 'sciq.e2e@example.test', password },
    chief: { email: 'chef.service.e2e@example.test', password },
    director: { email: 'directeur.e2e@example.test', password },
    planning: { email: 'planification.e2e@example.test', password },
    outsideAgent: { email: 'agent.autre.service.e2e@example.test', password },
};
