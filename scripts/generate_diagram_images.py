                                                                                                                                                                                                                                from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

import matplotlib.pyplot as plt
from matplotlib.patches import FancyBboxPatch


@dataclass
class Entity:
    name: str
    x: float
    y: float
    w: float
    h: float
    lines: list[str]


def draw_box(ax, entity: Entity, face: str = "#f8fafc") -> None:
    patch = FancyBboxPatch(
        (entity.x, entity.y),
        entity.w,
        entity.h,
        boxstyle="round,pad=0.25,rounding_size=0.2",
        linewidth=1.1,
        edgecolor="#334155",
        facecolor=face,
        zorder=2,
    )
    ax.add_patch(patch)

    ax.text(
        entity.x + 0.35,
        entity.y + entity.h - 0.6,
        entity.name,
        fontsize=8.4,
        fontweight="bold",
        va="top",
        color="#0f172a",
        zorder=3,
    )
    ax.plot(
        [entity.x + 0.25, entity.x + entity.w - 0.25],
        [entity.y + entity.h - 1.1, entity.y + entity.h - 1.1],
        color="#94a3b8",
        lw=0.8,
        zorder=3,
    )

    max_lines = max(1, int((entity.h - 1.5) // 0.58))
    for idx, line in enumerate(entity.lines[:max_lines]):
        ax.text(
            entity.x + 0.35,
            entity.y + entity.h - 1.45 - idx * 0.58,
            line,
            fontsize=6.8,
            va="top",
            color="#334155",
            zorder=3,
        )


def anchor(entity: Entity, side: str) -> tuple[float, float]:
    if side == "left":
        return (entity.x, entity.y + entity.h / 2.0)
    if side == "right":
        return (entity.x + entity.w, entity.y + entity.h / 2.0)
    if side == "top":
        return (entity.x + entity.w / 2.0, entity.y + entity.h)
    return (entity.x + entity.w / 2.0, entity.y)


def draw_relation(
    ax,
    start: tuple[float, float],
    end: tuple[float, float],
    label: str = "",
    rad: float = 0.0,
) -> None:
    ax.annotate(
        "",
        xy=end,
        xytext=start,
        arrowprops=dict(
            arrowstyle="-|>",
            color="#475569",
            lw=0.9,
            shrinkA=8,
            shrinkB=8,
            connectionstyle=f"arc3,rad={rad}",
        ),
        zorder=1,
    )
    if label:
        mx = (start[0] + end[0]) / 2.0
        my = (start[1] + end[1]) / 2.0
        ax.text(mx, my, label, fontsize=6.4, color="#334155", ha="center", va="center")


def generate_mcd(path: Path) -> None:
    entities = {
        "DIRECTIONS": Entity("DIRECTIONS", 2, 34, 11, 6.8, ["id PK", "code", "libelle", "actif"]),
        "SERVICES": Entity("SERVICES", 15, 34, 11, 7.4, ["id PK", "direction_id FK", "code", "libelle", "actif"]),
        "USERS": Entity(
            "USERS",
            28,
            33.4,
            12.5,
            8.6,
            ["id PK", "direction_id FK", "service_id FK", "name", "email", "role", "is_agent"],
        ),
        "PAS": Entity("PAS", 42.5, 34.2, 10.8, 7.2, ["id PK", "titre", "periode_debut", "periode_fin", "statut"]),
        "PAS_AXES": Entity("PAS_AXES", 55, 34.2, 10.8, 7.2, ["id PK", "pas_id FK", "code", "libelle", "ordre"]),
        "PAS_OBJECTIFS": Entity(
            "PAS_OBJECTIFS", 67.5, 34.2, 11.5, 7.2, ["id PK", "pas_axe_id FK", "code", "libelle", "indicateur_global"]
        ),
        "PAOS": Entity("PAOS", 81, 34.2, 11.5, 8.2, ["id PK", "pas_id FK", "direction_id FK", "annee", "titre", "statut"]),
        "PAO_AXES": Entity("PAO_AXES", 81, 24.2, 11.5, 6.6, ["id PK", "pao_id FK", "code", "libelle", "ordre"]),
        "PAO_OBJ_STRAT": Entity(
            "PAO_OBJ_STRAT", 67.5, 24.2, 12.8, 6.8, ["id PK", "pao_axe_id FK", "code", "libelle", "echeance"]
        ),
        "PAO_OBJ_OP": Entity(
            "PAO_OBJ_OP",
            52.5,
            22.8,
            13.8,
            9.4,
            ["id PK", "pao_objectif_strategique_id FK", "responsable_id FK", "description_action_detaillee", "cible_pourcentage", "statut_realisation"],
        ),
        "PTAS": Entity("PTAS", 38, 23.2, 12.5, 8.6, ["id PK", "pao_id FK", "direction_id FK", "service_id FK", "titre", "statut"]),
        "ACTIONS": Entity(
            "ACTIONS",
            22.5,
            22,
            14.5,
            10.2,
            ["id PK", "pta_id FK", "responsable_id FK", "libelle", "type_cible", "statut_dynamique", "financement_requis"],
        ),
        "ACTION_WEEKS": Entity(
            "ACTION_WEEKS",
            6,
            21.6,
            15,
            9.2,
            ["id PK", "action_id FK", "numero_semaine", "date_debut/date_fin", "quantite_realisee", "avancement_estime", "saisi_par FK"],
        ),
        "ACTION_KPIS": Entity(
            "ACTION_KPIS",
            22.5,
            10.8,
            14.5,
            8.0,
            ["id PK", "action_id FK UNIQUE", "kpi_delai", "kpi_performance", "kpi_conformite", "kpi_global"],
        ),
        "ACTION_JUSTIF": Entity(
            "ACTION_JUSTIF",
            38.8,
            10.8,
            14.8,
            8.6,
            ["id PK", "action_id FK", "action_week_id FK", "categorie", "nom_original", "ajoute_par FK"],
        ),
        "ACTION_LOGS": Entity(
            "ACTION_LOGS",
            55.8,
            10.8,
            14.8,
            8.6,
            ["id PK", "action_id FK", "action_week_id FK", "type_evenement", "cible_role", "utilisateur_id FK"],
        ),
        "KPIS": Entity("KPIS", 72.8, 12.0, 10.5, 7.2, ["id PK", "action_id FK", "libelle", "cible", "seuil_alerte"]),
        "KPI_MESURES": Entity(
            "KPI_MESURES", 85, 12.0, 12.0, 7.4, ["id PK", "kpi_id FK", "periode", "valeur", "saisi_par FK"]
        ),
        "JUSTIFICATIFS": Entity(
            "JUSTIFICATIFS", 72.8, 2.2, 12.4, 7.4, ["id PK", "justifiable_type", "justifiable_id", "nom_original", "ajoute_par FK"]
        ),
        "JOURNAL_AUDIT": Entity(
            "JOURNAL_AUDIT", 87, 2.2, 12.0, 7.4, ["id PK", "user_id FK", "module", "entite_type", "action"]
        ),
    }

    fig, ax = plt.subplots(figsize=(22, 12))
    ax.set_xlim(0, 100)
    ax.set_ylim(0, 44)
    ax.axis("off")
    ax.set_title("MCD - Application ANBG (PAS / PAO / PTA / Actions)", fontsize=14, fontweight="bold", pad=12)

    for entity in entities.values():
        draw_box(ax, entity)

    r = draw_relation
    a = anchor

    r(ax, a(entities["DIRECTIONS"], "right"), a(entities["SERVICES"], "left"), "1..N")
    r(ax, a(entities["DIRECTIONS"], "right"), a(entities["USERS"], "left"), "1..N", rad=-0.06)
    r(ax, a(entities["SERVICES"], "right"), a(entities["USERS"], "left"), "1..N", rad=0.08)

    r(ax, a(entities["PAS"], "right"), a(entities["PAS_AXES"], "left"), "1..N")
    r(ax, a(entities["PAS_AXES"], "right"), a(entities["PAS_OBJECTIFS"], "left"), "1..N")
    r(ax, a(entities["PAS"], "right"), a(entities["PAOS"], "left"), "1..N", rad=-0.15)
    r(ax, a(entities["DIRECTIONS"], "right"), a(entities["PAOS"], "left"), "1..N", rad=0.13)
    r(ax, a(entities["PAOS"], "bottom"), a(entities["PAO_AXES"], "top"), "1..N")
    r(ax, a(entities["PAO_AXES"], "left"), a(entities["PAO_OBJ_STRAT"], "right"), "1..N")
    r(ax, a(entities["PAO_OBJ_STRAT"], "left"), a(entities["PAO_OBJ_OP"], "right"), "1..N")
    r(ax, a(entities["USERS"], "bottom"), a(entities["PAO_OBJ_OP"], "top"), "resp", rad=0.2)

    r(ax, a(entities["PAOS"], "left"), a(entities["PTAS"], "right"), "1..N", rad=0.16)
    r(ax, a(entities["SERVICES"], "bottom"), a(entities["PTAS"], "top"), "N..N", rad=-0.1)
    r(ax, a(entities["PTAS"], "left"), a(entities["ACTIONS"], "right"), "1..N")
    r(ax, a(entities["USERS"], "left"), a(entities["ACTIONS"], "right"), "resp", rad=-0.2)
    r(ax, a(entities["ACTIONS"], "left"), a(entities["ACTION_WEEKS"], "right"), "1..N")
    r(ax, a(entities["ACTIONS"], "bottom"), a(entities["ACTION_KPIS"], "top"), "1..1")
    r(ax, a(entities["ACTIONS"], "right"), a(entities["ACTION_JUSTIF"], "left"), "1..N")
    r(ax, a(entities["ACTION_WEEKS"], "right"), a(entities["ACTION_JUSTIF"], "left"), "1..N", rad=0.16)
    r(ax, a(entities["ACTIONS"], "right"), a(entities["ACTION_LOGS"], "left"), "1..N", rad=-0.07)
    r(ax, a(entities["ACTION_WEEKS"], "right"), a(entities["ACTION_LOGS"], "left"), "1..N", rad=0.18)

    r(ax, a(entities["ACTIONS"], "right"), a(entities["KPIS"], "left"), "1..N", rad=-0.22)
    r(ax, a(entities["KPIS"], "right"), a(entities["KPI_MESURES"], "left"), "1..N")
    r(ax, a(entities["USERS"], "right"), a(entities["KPI_MESURES"], "top"), "saisi", rad=0.25)
    r(ax, a(entities["USERS"], "bottom"), a(entities["JOURNAL_AUDIT"], "left"), "trace", rad=-0.27)
    r(ax, a(entities["JUSTIFICATIFS"], "right"), a(entities["JOURNAL_AUDIT"], "left"), "audit", rad=0.1)

    path.parent.mkdir(parents=True, exist_ok=True)
    fig.tight_layout()
    fig.savefig(path, dpi=220)
    plt.close(fig)


def generate_uml(path: Path) -> None:
    classes = {
        "Direction": Entity("Direction", 3, 28, 12, 6, ["id", "code", "libelle"]),
        "Service": Entity("Service", 18, 28, 12, 6, ["id", "direction_id", "code"]),
        "User": Entity("User", 33, 27, 12.5, 7, ["id", "direction_id", "service_id", "role"]),
        "Pas": Entity("Pas", 48, 28, 10.8, 6.2, ["id", "periode_debut", "periode_fin"]),
        "Pao": Entity("Pao", 61, 28, 11, 6.2, ["id", "pas_id", "direction_id", "annee"]),
        "Pta": Entity("Pta", 74, 28, 11, 6.2, ["id", "pao_id", "service_id"]),
        "Action": Entity("Action", 48, 17, 12, 7.6, ["id", "pta_id", "type_cible", "statut_dynamique"]),
        "ActionWeek": Entity("ActionWeek", 33, 16, 13, 7.6, ["id", "action_id", "numero_semaine"]),
        "ActionKpi": Entity("ActionKpi", 63, 16, 12, 7.0, ["id", "action_id", "kpi_global"]),
        "Kpi": Entity("Kpi", 78, 16.5, 10.5, 6.5, ["id", "action_id", "libelle"]),
        "KpiMesure": Entity("KpiMesure", 90, 16.5, 9.5, 6.5, ["id", "kpi_id", "periode", "valeur"]),
        "ActionJustif": Entity("ActionJustif", 20, 6, 13, 7.2, ["id", "action_id", "action_week_id"]),
        "ActionLog": Entity("ActionLog", 35, 6, 13, 7.2, ["id", "action_id", "action_week_id"]),
        "Justificatif": Entity("Justificatif", 50, 6, 12, 7, ["id", "justifiable_type", "justifiable_id"]),
        "JournalAudit": Entity("JournalAudit", 65, 6, 12, 7, ["id", "user_id", "module", "action"]),
    }

    fig, ax = plt.subplots(figsize=(22, 10))
    ax.set_xlim(0, 100)
    ax.set_ylim(0, 36)
    ax.axis("off")
    ax.set_title("UML - Diagramme de classes (metier)", fontsize=14, fontweight="bold", pad=10)

    for entity in classes.values():
        draw_box(ax, entity, face="#fefce8")

    r = draw_relation
    a = anchor

    r(ax, a(classes["Direction"], "right"), a(classes["Service"], "left"), "1..*")
    r(ax, a(classes["Direction"], "right"), a(classes["User"], "left"), "1..*")
    r(ax, a(classes["Service"], "right"), a(classes["User"], "left"), "1..*", rad=0.12)
    r(ax, a(classes["Pas"], "right"), a(classes["Pao"], "left"), "1..*")
    r(ax, a(classes["Pao"], "right"), a(classes["Pta"], "left"), "1..*")
    r(ax, a(classes["Pta"], "bottom"), a(classes["Action"], "top"), "1..*")
    r(ax, a(classes["Action"], "left"), a(classes["ActionWeek"], "right"), "1..*")
    r(ax, a(classes["Action"], "right"), a(classes["ActionKpi"], "left"), "1..1")
    r(ax, a(classes["Action"], "right"), a(classes["Kpi"], "left"), "1..*")
    r(ax, a(classes["Kpi"], "right"), a(classes["KpiMesure"], "left"), "1..*")
    r(ax, a(classes["Action"], "bottom"), a(classes["ActionJustif"], "top"), "1..*")
    r(ax, a(classes["ActionWeek"], "bottom"), a(classes["ActionJustif"], "top"), "1..*", rad=0.15)
    r(ax, a(classes["Action"], "bottom"), a(classes["ActionLog"], "top"), "1..*")
    r(ax, a(classes["ActionWeek"], "bottom"), a(classes["ActionLog"], "top"), "0..*", rad=-0.15)
    r(ax, a(classes["Justificatif"], "right"), a(classes["JournalAudit"], "left"), "trace")
    r(ax, a(classes["User"], "bottom"), a(classes["JournalAudit"], "top"), "1..*", rad=-0.2)

    path.parent.mkdir(parents=True, exist_ok=True)
    fig.tight_layout()
    fig.savefig(path, dpi=220)
    plt.close(fig)


def main() -> None:
    output_dir = Path("docs/images")
    generate_mcd(output_dir / "mcd-anbg.png")
    generate_uml(output_dir / "uml-classes-anbg.png")


if __name__ == "__main__":
    main()
