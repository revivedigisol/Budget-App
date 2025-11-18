import { SWRConfig } from "swr";
import BudgetList from "./pages/BudgetList";
import BudgetEditor from "./pages/BudgetEditor";
import Reports from "./pages/Reports";
import CashBook from "./pages/CashBook";
import "./App.css";

// Configure SWR fetcher to use WordPress REST API with nonce
const fetcher = (url: string) =>
  fetch(url, {
    headers: {
      "X-WP-Nonce": (window as any).wpApiSettings?.nonce,
    },
  }).then((r) => r.json());

function App() {
  // Get initial route from WordPress
  const settings = (window as any).erpBudgetingSettings || {};
  const currentPage = settings.currentPage || "erp-budgeting";
  // If a `budget` query param is present we should render the editor for that
  // budget. WordPress sets `currentPage` based on the admin menu slug, but
  // editing a budget uses the same `erp-budgeting` slug with a `budget` query
  // param. Detect that and render `BudgetEditor` when appropriate.
  const urlParams = new URLSearchParams(window.location.search);
  const urlBudget = urlParams.get("budget");

  // Render the appropriate component based on the current WordPress admin page
  const PageComponent = () => {
    switch (currentPage) {
      case "erp-budgeting":
        // If a specific budget id was passed in the URL, show the editor
        if (urlBudget) return <BudgetEditor />;
        return <BudgetList />;
      case "erp-budgeting-new":
        return <BudgetEditor />;
      case "erp-budgeting-reports":
        return <Reports />;
      case "erp-budgeting-cashbook":
        return <CashBook />;
      default:
        return <BudgetList />;
    }
  };

  return (
    <SWRConfig value={{ fetcher }}>
      <div className="h-screen">
        <PageComponent />
      </div>
    </SWRConfig>
  );
}

export default App;
