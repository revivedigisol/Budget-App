import { Link } from 'react-router-dom'

const Navigation = () => {
  return (
    <nav className="erp-bg-wp-admin-menu erp-text-white erp-p-4">
      <div className="erp-container erp-mx-auto erp-flex erp-items-center erp-justify-between">
        <h1 className="erp-text-xl erp-font-semibold">ERP Budgeting</h1>
        <div className="erp-space-x-4">
          <Link to="/" className="erp-text-white erp-hover:text-gray-300">
            Budgets
          </Link>
          <Link to="/reports" className="erp-text-white erp-hover:text-gray-300">
            Reports
          </Link>
        </div>
      </div>
    </nav>
  )
}

export default Navigation