import { createRoot } from 'react-dom/client';
import { Component, type ReactNode } from 'react';
import App from './App';
import { appUrl, boot } from './laravel';
import './styles.css';
import './confirmation.css';
class ErrorBoundary extends Component<{ children: ReactNode }, { error: string }> {
  state = { error: '' };
  static getDerivedStateFromError(error: Error) { return { error: error.message }; }
  render() { return this.state.error ? <main className="error-page"><h1>Halaman belum dapat dimuat</h1><p>{this.state.error}</p><button onClick={() => location.assign(appUrl('/dashboard'))}>Kembali ke Dashboard</button></main> : this.props.children; }
}
createRoot(document.getElementById('root')!).render(boot?.account ? <ErrorBoundary><App/></ErrorBoundary> : <main className="error-page"><h1>Sesi aplikasi belum tersedia</h1><p>Muat ulang halaman melalui aplikasi Laravel dan masuk menggunakan akun Anda.</p><a href={appUrl('/login')}>Buka halaman login</a></main>);
