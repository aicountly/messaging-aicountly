import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import App from './App.tsx'
import { AuthProvider } from './auth/AuthProvider.tsx'
import { purgeLegacySharedAuthToken } from './auth/sharedAuthCookie'
import './index.css'

// Remove the retired shared auth_token cookie an older release may have left.
purgeLegacySharedAuthToken()

const rootElement = document.getElementById('root')
if (!rootElement) throw new Error('Root element #root not found')

createRoot(rootElement).render(
  <StrictMode>
    <AuthProvider>
      <App />
    </AuthProvider>
  </StrictMode>,
)
