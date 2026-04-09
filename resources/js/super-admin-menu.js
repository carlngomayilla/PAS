const getSuperAdminMenuHost = (menu) => menu.closest('.ui-card');

const syncSuperAdminMenuHost = (menu) => {
  const host = getSuperAdminMenuHost(menu);
  if (!(host instanceof HTMLElement)) {
    return;
  }

  host.classList.toggle('super-admin-menu-host-active', menu.open);
};

const closeSuperAdminMenus = (except = null) => {
  document.querySelectorAll('details.super-admin-menu[open]').forEach((menu) => {
    if (except !== null && menu === except) {
      return;
    }

    menu.removeAttribute('open');
    syncSuperAdminMenuHost(menu);
  });
};

const initSuperAdminMenu = () => {
  const menus = Array.from(document.querySelectorAll('details.super-admin-menu'));
  if (menus.length === 0) {
    return;
  }

  menus.forEach((menu) => {
    menu.addEventListener('toggle', () => {
      syncSuperAdminMenuHost(menu);

      if (!menu.open) {
        return;
      }

      closeSuperAdminMenus(menu);
      syncSuperAdminMenuHost(menu);
    });

    menu.querySelectorAll('a.super-admin-menu-link').forEach((link) => {
      link.addEventListener('click', () => {
        menu.removeAttribute('open');
        syncSuperAdminMenuHost(menu);
      });
    });

    syncSuperAdminMenuHost(menu);
  });

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Node)) {
      return;
    }

    const clickedInsideMenu = menus.some((menu) => menu.contains(target));
    if (!clickedInsideMenu) {
      closeSuperAdminMenus();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
      return;
    }

    const openMenus = menus.filter((menu) => menu.open);
    if (openMenus.length === 0) {
      return;
    }

    closeSuperAdminMenus();

    const summary = openMenus[0]?.querySelector('.super-admin-menu-trigger');
    if (summary instanceof HTMLElement) {
      summary.focus();
    }
  });
};

initSuperAdminMenu();
