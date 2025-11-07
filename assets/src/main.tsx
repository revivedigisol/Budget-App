import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "./index.css";
import App from "./App.tsx";
import { BrowserRouter } from "react-router-dom";

// Mount to the WP admin page root element rendered by PHP: <div id="erp-budgeting-root"></div>
createRoot(
  document.getElementById("erp-budgeting-root") ||
    document.getElementById("root")!
).render(
  <StrictMode>
    <BrowserRouter>
      <App />
    </BrowserRouter>
  </StrictMode>
);
