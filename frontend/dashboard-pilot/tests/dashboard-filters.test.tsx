import {
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
import { DashboardFilters } from '@/components/dashboard-filters';
import { dashboardFixture } from '@/tests/fixture';
import {
    navigationReplaceMock,
    resetNavigationMock,
    setNavigationSearchParameters,
} from '@/tests/mocks/next-navigation';

describe('dynamic dashboard filters', () => {
    beforeEach(() => {
        resetNavigationMock();
    });

    it('updates the URL immediately without an execution button', async () => {
        setNavigationSearchParameters('periode=q1');
        const user = userEvent.setup();

        render(<DashboardFilters data={dashboardFixture()} />);

        expect(screen.queryByRole('button', { name: /filtrer/i })).not.toBeInTheDocument();

        await user.selectOptions(screen.getByLabelText('Période'), 'q2');

        await waitFor(() => {
            expect(navigationReplaceMock).toHaveBeenCalledWith('/?periode=q2', { scroll: false });
        });
    });

    it('clears dependent service and responsible filters when direction changes', async () => {
        setNavigationSearchParameters('periode=q1&service_id=10&responsable_id=5');
        const user = userEvent.setup();

        render(<DashboardFilters data={dashboardFixture()} />);
        await user.selectOptions(screen.getByLabelText('Direction'), '2');

        await waitFor(() => {
            expect(navigationReplaceMock).toHaveBeenCalledWith(
                '/?periode=q1&direction_id=2',
                { scroll: false },
            );
        });
    });

    it('applies the action status immediately', async () => {
        const user = userEvent.setup();

        render(<DashboardFilters data={dashboardFixture()} />);
        await user.selectOptions(screen.getByLabelText('Statut de l’action'), 'a_corriger');

        await waitFor(() => {
            expect(navigationReplaceMock).toHaveBeenCalledWith(
                '/?statut_action=a_corriger',
                { scroll: false },
            );
        });
    });

    it('does not render filter controls when the API omits filter_options', () => {
        const payload = dashboardFixture();
        Reflect.deleteProperty(payload, 'filter_options');

        const { container } = render(<DashboardFilters data={payload} />);

        expect(container).toBeEmptyDOMElement();
    });
});
