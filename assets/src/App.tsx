import { SWRConfig } from 'swr'
import BudgetList from './pages/BudgetList'
import BudgetEditor from './pages/BudgetEditor'
import Reports from './pages/Reports'
import './App.css'

// Configure SWR fetcher to use WordPress REST API with nonce
const fetcher = (url: string) => 
  fetch(url, {
    headers: {
      'X-WP-Nonce': (window as any).wpApiSettings?.nonce
    }
  }).then(r => r.json())

function App() {
  // Get initial route from WordPress
  const settings = (window as any).erpBudgetingSettings || {};
  const currentPage = settings.currentPage || 'erp-budgeting';
  
  // Render the appropriate component based on the current WordPress admin page
  const PageComponent = () => {
    switch (currentPage) {
      case 'erp-budgeting':
        return <BudgetList />;
      case 'erp-budgeting-new':
        return <BudgetEditor />;
      case 'erp-budgeting-reports':
        return <Reports />;
      default:
        return <BudgetList />;
    }
  };

  return (
    <SWRConfig value={{ fetcher }}>
      <PageComponent />
    </SWRConfig>
  )
}

export default App
